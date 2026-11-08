<?php

declare(strict_types=1);

namespace Tinvar\Services;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\ServiceCall;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\UserLookup;
use Tinvar\Calls\CallHosts;
use Tinvar\Calls\CallResult;
use Tinvar\Calls\CallRunner;
use Tinvar\Calls\ContextToken;
use Tinvar\Calls\ContextTokens;
use Tinvar\Calls\Operation;
use Tinvar\Calls\Operations;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\RequirePanelMembership;
use Tinvar\Http\NonceStore;
use Tinvar\Http\Request;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\TokenError;

/**
 * Calls another system makes into this application, in a context this
 * application handed it: a service context token.
 *
 * When the application gives a service work that it will answer -- a
 * warehouse asked to collect an order, which says when it has -- it issues
 * the service a token (context()): signed under a key derived for service
 * context tokens alone, and bound to the service it is for, the operations
 * it may be used for, the tenant and panel it was issued in, the build that
 * issued it, an expiry, and the user it was issued for on their auth epoch,
 * if one was signed in. The service calls back with it.
 *
 * **A call is verified, then authorized.** call() refuses it unless:
 *
 *   - the caller is signed in as a Service -- by the application's own
 *     authentication, ahead of this -- and is the one the token was issued
 *     to: a token is no bearer credential, and one that leaks is no use to
 *     anyone else;
 *   - the operation is marked #[ServiceCall] for that service, and named
 *     by the token;
 *   - the token verifies, has not expired, was issued for no longer than a
 *     day, and by a build this server still answers for;
 *   - the tenant and panel the request is in, if any, are the token's: a
 *     token can narrow where a call runs, never move it;
 *   - the operation is in the token's panel; and, in a panel, the user the
 *     token was issued for is still there, on the same auth epoch, and still
 *     holds a membership of it meeting everything the operation's binding
 *     requires. A token issued for nobody reaches no panel's operation;
 *   - the host the call arrives on over HTTP is the token's (ADR 0149): one
 *     issued in a request is that host's, and one issued in a job names the
 *     host the service will call back on (context(host:)), or carries the
 *     no-host marker and is good only outside HTTP; and an operation held
 *     to a host -- by its #[Domain], or its panel's -- runs on that host
 *     alone ('host');
 *   - the call carries every argument the token was bound to, each exactly:
 *     a token issued about one record -- an order the warehouse is to
 *     collect -- says nothing of another.
 *
 * Then the operation runs in the token's tenant and panel, never across
 * tenants or a subtree, through the runner a browser's call goes through
 * (CallRunner), with the service as the user its #[Authorize] decides for.
 *
 * **A token is spent at its first call** (ADR 0119) unless it was issued
 * reusable: its id claimed in a store every server shares (NonceStore),
 * once every check above has passed and before the operation runs, until
 * the token would have expired. A second call with it is refused
 * ('spent') -- the one before was made, whatever it answered. Without a
 * store, a single-use token is refused rather than honoured more than once.
 */
final readonly class ServiceCalls
{
    /** The purpose a token is signed for, and its key derived for. */
    public const string PURPOSE = 'service-context';

    /** A token lasts an hour unless issued for less. */
    public const int TTL = 3600;

    /** And never more than a day: as long as a build is retained, so no token outlives the build that issued it by much. */
    public const int MAX_TTL = 86_400;

    /** The header a token travels in. */
    public const string HEADER = 'x-service-context';

    /** The scope a spent token's id is claimed under. */
    public const string SPENT = 'service-context-spent';

    /** @var array<string, array{Operation, ServiceCall}> by operation name */
    private array $operations;

    /**
     * @param list<array{Operation, ServiceCall}> $operations see discover()
     * @param ContextTokens $tokens built by tokens()
     * @param UserLookup|null $users finds a token's user again; without it, a
     *        token issued for a user is refused
     * @param NonceStore|null $spent where single-use tokens' ids are claimed, shared by every
     *        server -- a durable DatabaseNonceStore; without it, a single-use token is refused
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param CallHosts|null $hosts the hosts a token is bound to and an operation held to (ADR 0149);
     *        none, and the application is taken to route on no host
     */
    public function __construct(
        array $operations,
        private CallRunner $runner,
        private ContextTokens $tokens,
        private AuthContext $auth,
        private Orm $orm,
        private ?PanelContext $panels = null,
        private ?Memberships $memberships = null,
        private ?UserLookup $users = null,
        private ?NonceStore $spent = null,
        private ?\Closure $clock = null,
        private ?CallHosts $hosts = null,
    ) {
        $byName = [];
        foreach ($operations as [$operation, $declaration]) {
            // As tool calls do: a second of one name would replace the
            // first without a word, and a token naming it reach either.
            if (isset($byName[$operation->name])) {
                throw new \LogicException("Two service calls are named '{$operation->name}'.");
            }
            $byName[$operation->name] = [$operation, $declaration];
        }
        $this->operations = $byName;
        CallHosts::assertHolds($hosts, array_map(static fn (array $entry): Operation => $entry[0], $byName), 'the service calls');
    }

    /**
     * Every action and query the application marks #[ServiceCall].
     *
     * @return list<array{Operation, ServiceCall}>
     */
    public static function discover(ActionRegistry $actions, QueryRegistry $queries, EntryContext $entry): array
    {
        return Operations::declaring(ServiceCall::class, $actions, $queries, $entry);
    }

    /** The tokens' issuer, under a key derived from the application key for service context tokens alone. */
    public static function tokens(#[\SensitiveParameter] string $key, BuildRegistry $builds, ?\Closure $clock = null): ContextTokens
    {
        return new ContextTokens($key, self::PURPOSE, self::TTL, self::MAX_TTL, $builds, $clock);
    }

    /**
     * A token for $service to call these operations with, in the tenant and
     * panel in force, for whoever is signed in on their auth epoch.
     *
     * @param list<string> $operations each marked #[ServiceCall] for $service, and in this panel
     * @param int|null $ttl seconds; TTL unless given, never past MAX_TTL
     * @param bool $reusable whether it may be called with more than once, until it expires;
     *        spent at its first call unless so
     * @param array<string, string|int> $bound arguments a call with it must carry, each
     *        exactly: the record it is about, by its id; each an input of every operation named
     * @param string|null $host the host the service will call back on, where the application routes
     *        by host: named by code issuing a token outside a request -- a job -- since the token is
     *        that host's alone; in a request, the request's host, and none other may be named.
     *        Issued in a job naming none, the token is good only outside an HTTP request
     */
    public function context(string $service, array $operations, ?int $ttl = null, bool $reusable = false, array $bound = [], ?string $host = null): string
    {
        $user = $this->auth->user();
        if ($user instanceof Service) {
            throw new \LogicException('A service context token is issued by the application for a service, not by a service.');
        }
        if ($this->orm->isUnscoped()) {
            throw new \LogicException('A service context token cannot be issued inside acrossTenants(): it is held to one tenant.');
        }

        $panel = $this->panels?->currentOrNull() ?? '';
        $named = [];
        foreach ($operations as $name) {
            [$operation, $declaration] = $named[] = $this->operations[$name] ?? throw new \InvalidArgumentException("No operation named '{$name}' is marked #[ServiceCall].");
            if (!$declaration->allows($service)) {
                throw new \InvalidArgumentException("'{$name}' is not marked #[ServiceCall] for '{$service}'.");
            }
            if (($operation->panel->name ?? '') !== $panel) {
                throw new \LogicException("'{$name}' is in " . Operations::describePanel($operation->panel->name ?? '') . ', and this token would be for ' . Operations::describePanel($panel) . '.');
            }
            if ($operation->panel !== null && $user === null) {
                throw new \LogicException("'{$name}' is in a panel, and a token for it is issued for the member it acts for: nobody is signed in.");
            }
            // A call carries only what its operation takes, so a token bound
            // to anything else could never be called with: every call would
            // be refused as `context`. Caught here, where the name is a typo.
            foreach (array_keys($bound) as $argument) {
                if (!isset($operation->inputs[$argument])) {
                    throw new \InvalidArgumentException("The token is bound to '{$argument}', which '{$name}' does not take.");
                }
            }
        }

        $bindsHost = ($this->hosts ?? new CallHosts())->issuing(array_map(static fn (array $entry): Operation => $entry[0], $named), $host);

        return $this->tokens->issue($service, $operations, $this->orm->tenant(), $panel, $user?->authIdentifier(), $this->auth->epoch(), $ttl, singleUse: !$reusable, bound: $bound, host: $bindsHost, impersonation: $user === null ? null : $this->auth->impersonation(), impersonator: $user === null ? null : $this->auth->impersonator());
    }

    /** The token a request carries, in HEADER; '' for none. */
    public static function tokenOf(Request $request): string
    {
        return $request->header(self::HEADER) ?? '';
    }

    /**
     * Runs $operation for the signed-in service, in the context its token
     * was issued for.
     *
     * @param array<array-key, mixed> $arguments the service's input, decoded from its JSON
     */
    public function call(string $token, string $operation, array $arguments): CallResult
    {
        $service = $this->auth->user();
        if (!$service instanceof Service) {
            return CallResult::refused('unauthenticated');
        }

        // An operation the service may not call reads as one that does not
        // exist, so its names are not learnt by asking.
        $entry = $this->operations[$operation] ?? null;
        if ($entry === null || !$entry[1]->allows($service->serviceName())) {
            return CallResult::refused('unknown_operation');
        }
        [$declared] = $entry;

        try {
            $claims = $this->tokens->open($token);
        } catch (TokenError) {
            return CallResult::refused('context');
        }
        // A token that does not say whether it is spent was issued before
        // tokens said: refused, rather than taken as reusable.
        if ($claims->singleUse === null || !$this->fits($claims, $service, $operation) || !$claims->binds($arguments)) {
            return CallResult::refused('context');
        }
        if (($declared->panel->name ?? '') !== $claims->panel || ($claims->panel !== '' && $this->panels === null)) {
            return CallResult::refused('panel');
        }
        if (!($this->hosts ?? new CallHosts())->holds($declared, $claims->host)) {
            return CallResult::refused('host');
        }

        return $this->orm->actAs($claims->tenant, fn (): CallResult => $this->orm->withoutSubtreeGrant(function () use ($claims, $declared, $arguments): CallResult {
            $member = $this->member($claims, $declared);
            if ($member instanceof CallResult) {
                return $member;
            }

            // Spent here, every check passed, before the operation runs:
            // a call refused above leaves the token for the call that fits.
            // That includes arguments the runner would refuse for their
            // shape alone -- a field the operation does not take, a list
            // for an object -- before anything is resolved or validated; a
            // call that fails its validation has been answered, and spends
            // it (ADR 0119).
            if ($claims->singleUse === true) {
                $misshapen = CallRunner::admit($declared, $arguments);
                if ($misshapen !== null) {
                    return $misshapen;
                }
                if ($this->spent === null) {
                    return CallResult::refused('context');
                }
                $now = $this->clock === null ? time() : ($this->clock)();
                if (!$this->spent->claim(self::SPENT, $claims->id, $claims->expiresAt, $now)) {
                    return CallResult::refused('spent');
                }
            }

            $run = fn (): CallResult => $this->runner->run($declared, $arguments);
            // A token minted while staff impersonated its user (ADR 0194):
            // the call is recorded with who was acting as them.
            if ($claims->impersonator !== null && $claims->impersonator !== '') {
                $service = $this->auth->user();
                $plain = $run;
                $run = fn (): CallResult => $this->auth->actingAs($service, $plain, $this->auth->epoch(), $claims->impersonator);
            }
            if ($declared->panel === null || $this->panels === null) {
                return $run();
            }
            $panels = $this->panels;
            $binding = $declared->panel;

            // Held to the binding through the member the token acts for, so
            // a query's own panel check need not ask the service for a
            // membership it cannot have.
            return $panels->within($binding->name, static fn (): CallResult => $panels->grantedWithin($binding, $run));
        }));
    }

    /** Whether a token was issued to this service, for this operation, in the context the call is made in. */
    private function fits(ContextToken $claims, Service $service, string $operation): bool
    {
        if (!hash_equals($claims->audience, $service->serviceName()) || !$claims->allows($operation)
            || !($this->hosts ?? new CallHosts())->admits($claims->host)) {
            return false;
        }
        if ($this->orm->isUnscoped()) {
            return false;
        }

        $tenant = $this->orm->tenant();
        $panel = $this->panels?->currentOrNull();

        return ($tenant === '' || hash_equals($claims->tenant, $tenant))
            && ($panel === null || hash_equals($claims->panel, $panel));
    }

    /**
     * Null when the token's user may still have the operation run in its
     * panel; the refusal otherwise. Asked inside the token's tenant, where
     * the membership is held.
     */
    private function member(ContextToken $claims, Operation $operation): ?CallResult
    {
        $user = null;
        $epoch = '';
        if ($claims->user !== '') {
            if ($this->users === null) {
                return CallResult::refused('context');
            }
            $user = $this->users->find($claims->user);
            // Bound to the impersonation it was minted in, where it was
            // (ADR 0194): it ends with the epoch that impersonation's end moves.
            $epoch = $user === null ? '' : AuthContext::boundEpoch($this->users->epoch($user), $claims->impersonation);
            if ($user === null || !hash_equals($claims->authHash, $this->tokens->authHash($claims->user, $epoch))) {
                return CallResult::refused('context');
            }
        }

        $binding = $operation->panel;
        if ($binding === null) {
            return null;
        }
        if (!$user instanceof Authenticatable) {
            return CallResult::refused('panel');
        }
        if ($this->memberships === null) {
            return $binding->narrows() ? CallResult::refused('panel') : null;
        }

        $memberships = $this->memberships;
        try {
            $this->auth->actingAs($user, fn () => RequirePanelMembership::verify($this->auth, $memberships, $this->orm, $binding), $epoch, $claims->impersonator);
        } catch (HttpException) {
            return CallResult::forbidden();
        }

        return null;
    }
}
