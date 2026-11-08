<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\AcceptsTenantTokens;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\RequiresScope;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Calls\CallHosts;
use Tinvar\Calls\CallResult;
use Tinvar\Calls\CallRunner;
use Tinvar\Calls\Operation;
use Tinvar\Calls\Operations;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\RequirePanelMembership;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;

/**
 * The actions and queries marked #[RequiresScope], called with an API token
 * that holds (ADR 0159): what `POST /_api/{operation}` runs.
 *
 * Through the machinery a tool call and a service call go through (ADR
 * 0098): the operations are read by Operations -- so an action that takes
 * the Request, an upload or #[Middleware] is refused when the application
 * is built -- and run by CallRunner, the runner a browser's call goes
 * through: model ids resolved in the tenant, every #[Authorize] decided for
 * the token's user, every input validated, the value encoded.
 *
 * **A call is held, then run.** call() refuses it unless:
 *
 *   - the operation is one marked #[RequiresScope], and the token holds
 *     every scope it names (`unknown_operation`, `insufficient_scope`);
 *   - it is in the token's panel (`panel`), and, over HTTP, on the host it
 *     is held to by its #[Domain] or its panel's (`host`) -- the token was
 *     held to its own host when it signed its user in;
 *   - the tenant in force, if any, is the token's, outside acrossTenants()
 *     (`tenant`): a token can narrow where a call runs, never move it.
 *
 * Then it runs as the token's user on their epoch, in the token's tenant,
 * never across tenants or a subtree; in a panel, only while the user still
 * holds a membership of it meeting everything the operation's binding
 * requires, and only past the panel's guard and its own middleware -- MFA,
 * a network allowlist -- run on the call's request, as they run for a page
 * or an endpoint in the panel. A token outlives the request that issued it,
 * so what the panel checked then is checked again at every call.
 *
 * **A tenant's token** (ADR 0193) is held the same way, and only to an
 * operation marked #[AcceptsTenantTokens] (`tenant_token_not_accepted`
 * otherwise); it runs as its TenantTokenActor, whose membership of a panel
 * is the application's Memberships' to answer, as anyone's is.
 */
final readonly class ApiCalls
{
    /** @var array<string, array{Operation, list<string>}> by operation name: each, and the scopes it requires */
    private array $operations;

    /** @var array<string, true> the operations a tenant's token may call, by name (ADR 0193) */
    private array $tenantTokens;

    /**
     * @param list<array{Operation, RequiresScope}> $operations see discover()
     * @param CallHosts|null $hosts the hosts an operation is held to; none, and the application is
     *        taken to route on no host
     * @param list<string> $tenantTokenOperations the operations a tenant's token may call, by
     *        name (acceptingTenantTokens()); each must be among $operations
     */
    public function __construct(
        array $operations,
        private CallRunner $runner,
        private AuthContext $auth,
        private Orm $orm,
        private ?PanelContext $panels = null,
        private ?Memberships $memberships = null,
        private ?CallHosts $hosts = null,
        private ?EntryContext $entry = null,
        array $tenantTokenOperations = [],
    ) {
        $byName = [];
        foreach ($operations as [$operation, $declaration]) {
            if (isset($byName[$operation->name])) {
                throw new \LogicException("Two operations marked #[RequiresScope] are named '{$operation->name}'.");
            }
            $byName[$operation->name] = [$operation, $declaration->scopes];
        }
        $this->operations = $byName;
        $accepting = [];
        foreach ($tenantTokenOperations as $name) {
            if (!isset($byName[$name])) {
                throw new \LogicException("The operation '{$name}' accepts tenant tokens and requires no scope: mark it #[RequiresScope] too, or take #[AcceptsTenantTokens] away.");
            }
            $accepting[$name] = true;
        }
        $this->tenantTokens = $accepting;
        CallHosts::assertHolds($hosts, array_map(static fn (array $entry): Operation => $entry[0], $byName), 'the API calls');
    }

    /**
     * The names of the operations among $operations that accept tenant
     * tokens (ADR 0193), from what carries #[AcceptsTenantTokens] --
     * Operations::declaring()'s or described()'s. One that requires no
     * scope is refused: nothing would be exposed, while it read as though
     * something were. So is one on an action holder's class, as
     * #[RequiresScope] is.
     *
     * @param list<array{Operation, object}> $accepting
     * @param list<array{Operation, RequiresScope}> $operations
     * @return list<string>
     */
    public static function acceptingTenantTokens(array $accepting, array $operations, ActionRegistry $actions): array
    {
        foreach ($actions->all() as $action) {
            if (new \ReflectionClass($action->class)->getAttributes(AcceptsTenantTokens::class) !== []) {
                throw new \LogicException(
                    "{$action->class} carries #[AcceptsTenantTokens] on its class. Name it on each #[Action] method a tenant's token may call, "
                    . 'so what a tenant\'s token reaches is read where each action is.',
                );
            }
        }
        $exposed = [];
        foreach ($operations as [$operation]) {
            $exposed[$operation->name] = true;
        }
        $names = [];
        foreach ($accepting as [$operation]) {
            if (!isset($exposed[$operation->name])) {
                throw new \LogicException("{$operation->class} ({$operation->name}) accepts tenant tokens and requires no scope: mark it #[RequiresScope] too, or take #[AcceptsTenantTokens] away.");
            }
            $names[] = $operation->name;
        }

        return $names;
    }

    /**
     * Refuses an entry point that accepts tenant tokens while a check on it
     * would be decided by a policy method -- the ability, or the policy's
     * before() -- that does not name TenantTokenActor (PolicyGate::
     * admitsTenantTokens()): every call it took would be refused, and the
     * policy's author never said what a token may do. Read when the Kernel
     * is built, from the declarations alone.
     *
     * @param list<array{string, list<Authorize>, array<string, string>}> $entries each entry
     *        point: where it is, its checks, and its model inputs by name
     * @throws \LogicException naming the first policy method that does not name the actor
     */
    public static function assertTenantTokenPolicies(PolicyRegistry $policies, array $entries): void
    {
        foreach ($entries as [$where, $checks, $models]) {
            foreach ($checks as $check) {
                $argument = (string) $check->argument;
                $subject = $models[$argument] ?? (class_exists($argument) || interface_exists($argument) ? $argument : null);
                /** @var class-string|null $subject */
                $policy = $subject === null ? null : $policies->classFor($subject);
                if ($policy === null) {
                    continue;
                }
                foreach (['before', $check->ability] as $method) {
                    $reflection = new \ReflectionClass($policy);
                    $public = $reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic();
                    if ($method === 'before' && !$public) {
                        continue;
                    }
                    if (!PolicyGate::admitsTenantTokens($policy, $method)) {
                        throw new \LogicException(
                            "{$where} accepts tenant tokens, and #[Authorize('{$check->ability}', '{$argument}')] is decided by {$policy}::{$method}(), "
                            . 'whose first parameter does not name Tinvar\Auth\TenantTokenActor. Type it as `User|TenantTokenActor|null $actor` '
                            . 'and say what an organization\'s token may do, or take #[AcceptsTenantTokens] away.',
                        );
                    }
                }
            }
        }
    }

    /** Whether a tenant's token may call $operation (#[AcceptsTenantTokens]). */
    public function acceptsTenantTokens(string $operation): bool
    {
        return isset($this->tenantTokens[$operation]);
    }

    /**
     * Every action and query the application marks #[RequiresScope]. One on
     * an action holder's class is refused: Operations reads an action's own
     * method, and a class-wide requirement would expose nothing while
     * reading as though it did.
     *
     * @return list<array{Operation, RequiresScope}>
     */
    public static function discover(ActionRegistry $actions, QueryRegistry $queries, EntryContext $entry): array
    {
        self::refuseOnActionHolders($actions);

        return Operations::declaring(RequiresScope::class, $actions, $queries, $entry);
    }

    /**
     * Refuses #[RequiresScope] on the class of a registered action holder.
     */
    public static function refuseOnActionHolders(ActionRegistry $actions): void
    {
        foreach ($actions->all() as $action) {
            if (new \ReflectionClass($action->class)->getAttributes(RequiresScope::class) !== []) {
                throw new \LogicException(
                    "{$action->class} carries #[RequiresScope] on its class. Name it on each #[Action] method to expose over the API, "
                    . 'so what is exposed is read where each action is.',
                );
            }
        }
    }

    /**
     * Every operation exposed, with the scopes it requires, by name.
     *
     * @return array<string, array{Operation, list<string>}>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * Runs $operation for the user $presented signed in -- or, for a
     * tenant's token, as the token itself (ADR 0193) -- held to what the
     * token allows.
     *
     * @param array<array-key, mixed> $arguments the caller's input, decoded from its JSON
     * @param Request|null $request the call's request, which a panel's guard and middleware run
     *        on; without one, an operation in a panel that has either is refused
     */
    public function call(PresentedApiToken|PresentedTenantApiToken $presented, string $operation, array $arguments, ?Request $request = null): CallResult
    {
        $entry = $this->operations[$operation] ?? null;
        if ($entry === null) {
            return CallResult::refused('unknown_operation');
        }
        // A tenant's token reaches only what says it may: its call runs as
        // no user, which a policy written for people never expected.
        if ($presented instanceof PresentedTenantApiToken && !isset($this->tenantTokens[$operation])) {
            return CallResult::refused('tenant_token_not_accepted');
        }
        [$declared, $scopes] = $entry;
        $token = $presented->token;

        if (!$token->grants($scopes)) {
            return CallResult::refused('insufficient_scope');
        }
        if (!hash_equals($declared->panel->name ?? '', $token->panel) || ($token->panel !== '' && $this->panels === null)) {
            return CallResult::refused('panel');
        }
        if (!($this->hosts ?? new CallHosts())->holds($declared, $token->host)) {
            return CallResult::refused('host');
        }
        $tenant = $this->orm->isUnscoped() ? null : $this->orm->tenant();
        if ($tenant === null || ($tenant !== '' && !hash_equals($token->tenant, $tenant))) {
            return CallResult::refused('tenant');
        }

        return $this->auth->actingAs(
            $presented instanceof PresentedApiToken ? $presented->user : $presented->actor,
            fn (): CallResult => $this->orm->actAs($token->tenant, fn (): CallResult => $this->orm->withoutSubtreeGrant(
                fn (): CallResult => $this->run($declared, $arguments, $request),
            )),
            $presented instanceof PresentedApiToken ? $presented->epoch : $presented->epoch(),
        );
    }

    /** @param array<array-key, mixed> $arguments */
    private function run(Operation $operation, array $arguments, ?Request $request): CallResult
    {
        $run = fn (): CallResult => $this->runner->run($operation, $arguments);
        $binding = $operation->panel;
        if ($binding === null || $this->panels === null) {
            return $run();
        }

        // The token's user, a member of the panel now, in this tenant, as
        // everything the binding requires: a membership taken away since
        // the token was issued ends what it reaches there.
        if ($this->memberships === null) {
            if ($binding->narrows()) {
                return CallResult::refused('panel');
            }
        } else {
            try {
                RequirePanelMembership::verify($this->auth, $this->memberships, $this->orm, $binding);
            } catch (HttpException) {
                return CallResult::forbidden();
            }
        }

        $panels = $this->panels;
        $held = static fn (): CallResult => $panels->grantedWithin($binding, $run);
        $guards = $this->entry?->panelGuards($binding) ?? [];

        return $panels->within($binding->name, static function () use ($guards, $request, $held): CallResult {
            if ($guards === []) {
                return $held();
            }
            // The panel's guard and middleware ran when the token was issued;
            // a token is good long after, from anywhere, so they run again on
            // this call's request: an admin since removed, or a call from off
            // the allowed network, is refused here as on a page of the panel
            // -- and a platform-only panel's operation asked for on a
            // tenant's custom domain is not there, as its pages are not.
            if ($request === null) {
                return CallResult::refused('panel');
            }
            $result = null;
            $reached = false;
            try {
                new Pipeline($guards)->run($request, static function () use (&$result, &$reached, $held): Response {
                    $reached = true;
                    $result = $held();

                    return Response::make(204);
                });
            } catch (HttpException $e) {
                // The panel's refusal; one from the call itself is its own.
                if ($reached) {
                    throw $e;
                }

                // A platform-only panel asked for on another host: not there,
                // as a page of the panel is not (RequirePlatformHost).
                return $e->status === 404 ? CallResult::refused('host') : CallResult::forbidden();
            }

            // A guard that answered without passing the call on -- a redirect
            // to sign in -- let nothing run.
            return $result ?? CallResult::forbidden();
        });
    }
}
