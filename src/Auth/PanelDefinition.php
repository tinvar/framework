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
 *
 * `subtree` is the one widening a panel can say: "Widening (a reseller's
 * customers ...) comes from the session's membership: its tenant kind plus
 * a panel permission." A request let in by the membership check of a panel
 * defined with it may read across its tenant's subtree, with
 * Orm::withinSubtree(); nothing else may. So a definition saying it names
 * both the tenant kind and the permission its memberships must have.
 */
final readonly class PanelDefinition
{
    /**
     * @param class-string<Middleware>|null $guard
     * @param list<class-string<Middleware>> $middleware MFA, network allowlist, email verification...
     * @param list<string> $namespaces e.g. `App\Admin\`
     * @param \UnitEnum|string|null $requires the tenant kind every membership here must have
     * @param string|null $permission one every membership here must hold
     * @param bool $subtree whether a membership here lets the request read across its
     *        tenant's subtree (Orm::withinSubtree()): a reseller's customers
     * @param string|null $domain the host the panel lives on, as #[Domain] writes it
     *        (`admin.{root}`): every page, layout and endpoint in it declares that host,
     *        and every request into it -- an action or a query's too -- is refused on
     *        any other (ADR 0149)
     */
    public function __construct(
        public string $name,
        public ?string $guard = null,
        public array $middleware = [],
        public array $namespaces = [],
        public \UnitEnum|string|null $requires = null,
        public ?string $permission = null,
        public bool $subtree = false,
        public ?string $domain = null,
    ) {
        if ($domain !== null) {
            try {
                \Tinvar\Routing\HostPattern::compile($domain);
            } catch (\Tinvar\Routing\HostPatternRefused $e) {
                throw new \InvalidArgumentException("Panel '{$name}': {$e->getMessage()}", 0, $e);
            }
        }

        // The same rules the attribute holds itself to: an empty name is no
        // panel, and an empty requirement is one nobody could tell was there.
        new Panel($name, $requires, $permission);

        if ($subtree && ($requires === null || $permission === null)) {
            throw new \InvalidArgumentException(
                "Panel '{$name}' reaches its tenant's subtree, and widening comes from the membership -- its tenant kind "
                . 'plus a panel permission -- so its definition names both, with requires: and permission:.',
            );
        }

        foreach ($namespaces as $namespace) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\$/', $namespace) !== 1) {
                throw new \InvalidArgumentException(
                    "'{$namespace}' is not a namespace prefix; write it with its trailing backslash, as `App\\Admin\\`.",
                );
            }
        }
    }
}
