<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelBinding;
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
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            throw new HttpException(401, "Panel '{$this->panel->name}' needs a signed-in member.", 'unauthenticated');
        }

        $membership = $this->memberships->membership($user, $this->panel->name, $this->orm->tenant());
        if ($membership === null) {
            throw self::refuse("is not a member of panel '{$this->panel->name}' in this tenant");
        }

        foreach ($this->panel->kinds as $kind) {
            if (!$membership->isKind($kind)) {
                throw self::refuse("holds a membership of panel '{$this->panel->name}' in a tenant that is not a {$kind}");
            }
        }

        foreach ($this->panel->permissions as $permission) {
            if (!$membership->can($permission)) {
                throw self::refuse("lacks '{$permission}' in panel '{$this->panel->name}'");
            }
        }

        return $next($request->withAttribute(self::ATTRIBUTE, $membership));
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
