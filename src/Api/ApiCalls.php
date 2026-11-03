<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\RequiresScope;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
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
 */
final readonly class ApiCalls
{
    /** @var array<string, array{Operation, list<string>}> by operation name: each, and the scopes it requires */
    private array $operations;

    /**
     * @param list<array{Operation, RequiresScope}> $operations see discover()
     * @param CallHosts|null $hosts the hosts an operation is held to; none, and the application is
     *        taken to route on no host
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
    ) {
        $byName = [];
        foreach ($operations as [$operation, $declaration]) {
            if (isset($byName[$operation->name])) {
                throw new \LogicException("Two operations marked #[RequiresScope] are named '{$operation->name}'.");
            }
            $byName[$operation->name] = [$operation, $declaration->scopes];
        }
        $this->operations = $byName;
        CallHosts::assertHolds($hosts, array_map(static fn (array $entry): Operation => $entry[0], $byName), 'the API calls');
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
     * Runs $operation for the user $presented signed in, held to what the
     * token allows.
     *
     * @param array<array-key, mixed> $arguments the caller's input, decoded from its JSON
     * @param Request|null $request the call's request, which a panel's guard and middleware run
     *        on; without one, an operation in a panel that has either is refused
     */
    public function call(PresentedApiToken $presented, string $operation, array $arguments, ?Request $request = null): CallResult
    {
        $entry = $this->operations[$operation] ?? null;
        if ($entry === null) {
            return CallResult::refused('unknown_operation');
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
            $presented->user,
            fn (): CallResult => $this->orm->actAs($token->tenant, fn (): CallResult => $this->orm->withoutSubtreeGrant(
                fn (): CallResult => $this->run($declared, $arguments, $request),
            )),
            $presented->epoch,
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
