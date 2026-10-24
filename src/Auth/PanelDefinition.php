<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Panel;
use Tinvar\Http\Middleware;

/**
 * One panel: what runs in front of everything in it, and which classes are
 * in it without saying so.
 *
 * tinvar-spec.md gives an action "the full middleware stack of the action's
 * panel (guard, MFA, network allowlist, email verification)", and the same
 * stack to a query called over HTTP and to every page and fragment in the
 * panel. The stack runs in that order:
 *
 *   1. `guard` -- who may be here at all: signs the request's user in, or
 *      sends a visitor who is not to sign in.
 *   2. The membership check, built in: the user's membership of the panel
 *      in the request's tenant, with `requires` and `permission` held to it
 *      (see Memberships). Present whenever the application supplies
 *      Memberships.
 *   3. `middleware`, in order: the panel's extension points -- MFA, a
 *      network allowlist, email verification -- each an ordinary Middleware
 *      the application writes against its own users and factors.
 *
 * then the entry point's own middleware. `namespaces` puts every page,
 * layout, action and query under a namespace in the panel unless it
 * declares a #[Panel] of its own -- "by #[Panel] or by namespace".
 */
final readonly class PanelDefinition
{
    /**
     * @param class-string<Middleware>|null $guard
     * @param list<class-string<Middleware>> $middleware MFA, network allowlist, email verification...
     * @param list<string> $namespaces e.g. `App\Admin\`
     * @param \UnitEnum|string|null $requires the tenant kind every membership here must have
     * @param string|null $permission one every membership here must hold
     */
    public function __construct(
        public string $name,
        public ?string $guard = null,
        public array $middleware = [],
        public array $namespaces = [],
        public \UnitEnum|string|null $requires = null,
        public ?string $permission = null,
    ) {
        // The same rules the attribute holds itself to: an empty name is no
        // panel, and an empty requirement is one nobody could tell was there.
        new Panel($name, $requires, $permission);

        foreach ($namespaces as $namespace) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\$/', $namespace) !== 1) {
                throw new \InvalidArgumentException(
                    "'{$namespace}' is not a namespace prefix; write it with its trailing backslash, as `App\\Admin\\`.",
                );
            }
        }
    }
}
