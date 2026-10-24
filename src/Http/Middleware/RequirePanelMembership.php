<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;

/**
 * The membership check in a panel's stack: the user belongs to the panel in
 * the request's tenant, as the kind of tenant and with the permissions the
 * panel's declarations and definition require.
 *
 * "Widening ... comes from the session's membership: its tenant kind plus a
 * panel permission." So `requires:` and `permission:` are held to what the
 * application's Memberships says, not carried for something later to read.
 * After the panel's guard, which says who the user is, and after the
 * tenant, which says where the membership has to be held.
 *
 * Nobody signed in is a 401, which the client reads as the session ending;
 * a user with no membership, or the wrong one, is a 403.
 *
 * The one widening is made here, and only after the check passes: in a
 * panel defined with `subtree: true`, whose memberships carry the tenant
 * kind and permission the definition names, the rest of the request may
 * read across its tenant's subtree (Orm::grantSubtree()).
 */
final readonly class RequirePanelMembership implements Middleware
{
    /** The request attribute carrying the Membership the check found. */
    public const string ATTRIBUTE = 'tinvar.membership';

    public function __construct(
        private AuthContext $auth,
        private Memberships $memberships,
        private Orm $orm,
        private PanelBinding $panel,
        private PanelContext $panels,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $membership = self::verify($this->auth, $this->memberships, $this->orm, $this->panel);

        $run = static fn (): Response => $next($request->withAttribute(self::ATTRIBUTE, $membership));
        if ($this->panel->subtree) {
            $orm = $this->orm;
            $exact = $run;
            $run = static fn (): Response => $orm->grantSubtree($exact);
        }

        // The rest of the request runs as having been held to this binding,
        // so a query it calls that needs no more is not asked about again.
        return $this->panels->grantedWithin($this->panel, $run);
    }

    /**
     * The signed-in user's membership, held to everything $panel requires.
     *
     * @throws HttpException 401 for nobody signed in, 403 for no membership or the wrong one
     */
    public static function verify(AuthContext $auth, Memberships $memberships, Orm $orm, PanelBinding $panel): Membership
    {
        $user = $auth->user();
        if ($user === null) {
            throw new HttpException(401, "Panel '{$panel->name}' needs a signed-in member.", 'unauthenticated');
        }

        $membership = $memberships->membership($user, $panel->name, $orm->tenant());
        if ($membership === null) {
            throw self::refuse("is not a member of panel '{$panel->name}' in this tenant");
        }

        foreach ($panel->kinds as $kind) {
            if (!$membership->isKind($kind)) {
                throw self::refuse("holds a membership of panel '{$panel->name}' in a tenant that is not a {$kind}");
            }
        }

        foreach ($panel->permissions as $permission) {
            if (!$membership->can($permission)) {
                throw self::refuse("lacks '{$permission}' in panel '{$panel->name}'");
            }
        }

        return $membership;
    }

    /** The membership a request passed this check with, or null when none did. */
    public static function of(Request $request): ?Membership
    {
        $membership = $request->attribute(self::ATTRIBUTE);

        return $membership instanceof Membership ? $membership : null;
    }

    private static function refuse(string $why): HttpException
    {
        return new HttpException(403, "The user {$why}.", 'forbidden');
    }
}
