<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\SubtreeMemberships;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Identity\Realms;
use Tinvar\Orm\Orm;

/**
 * Moving a signed-in session from one of its user's tenants to another.
 *
 * tinvar-spec.md, "Identity across domains": "A session created on [a
 * custom domain] can only hold memberships within that domain's tenant
 * subtree, and the tenant switcher is limited to that subtree." And from
 * "Client-held data": what the client holds "is cleared, with a full page
 * load, on ... tenant switch".
 *
 * Two calls, for the application's own page and action -- which bring the
 * CSRF check, the session and the throttle a switch needs, as any action
 * does:
 *
 * - choices() lists where the signed-in user may go: the tenants they hold
 *   a membership of the panel in, at or below the tenant of the realm the
 *   request arrived on. On a custom domain, that is the domain's subtree;
 *   on a platform host naming no tenant, every tenant of theirs.
 * - switch() moves the session to one of them, held to the same rules
 *   again, and hands the application the switch (TenantSessions) -- which
 *   must answer with a new session id. A request's tenants, panels and
 *   tokens all follow from the session, so the next page is a new binding,
 *   and the client purges what it held for the old one.
 *
 * Every refusal is the same 403, `tenant_switch_refused`, so a caller
 * cannot tell a tenant that exists elsewhere from one that exists nowhere.
 */
final readonly class TenantSwitcher
{
    private SubtreeMemberships $members;

    /**
     * @param Memberships&TenantMemberships $memberships the application's: listed, then
     *        each tenant confirmed, as the panel's own check asks
     * @param \Closure(Request): string $sessionId the session a request carries, '' for none:
     *        the resolver every token is bound with
     * @param Realms|null $realms the application's hosts; given, a switch is held to the
     *        subtree of the tenant the request's host belongs to
     */
    public function __construct(
        Orm $orm,
        private AuthContext $auth,
        Memberships&TenantMemberships $memberships,
        private TenantSessions $sessions,
        private \Closure $sessionId,
        private ?Realms $realms = null,
    ) {
        $this->members = new SubtreeMemberships($orm, $memberships);
    }

    /**
     * The tenants the signed-in user may switch to here, holding a
     * membership of $panel in each, in the application's order.
     *
     * @return list<string>
     * @throws HttpException 401 for nobody signed in; as switch() for a host that is no realm
     */
    public function choices(Request $request, string $panel): array
    {
        $user = $this->auth->user() ?? throw self::unauthenticated();

        return $this->members->tenants($user, $panel, $this->root($request, $panel));
    }

    /**
     * Moves the request's session to $tenant.
     *
     * @return string the new session's id, which the application set on the response
     * @throws HttpException 401 for nobody signed in, 403 for a tenant this user may not
     *         switch to here, 404 for a host that is no realm
     */
    public function switch(Request $request, string $tenant, string $panel): string
    {
        $user = $this->auth->user() ?? throw self::unauthenticated();

        $root = $this->root($request, $panel);
        if ($tenant === '' || ($root !== null && !$this->members->within($root, $tenant))) {
            throw self::refused("'{$tenant}' is not in the subtree this host's tenant bounds");
        }
        if (!$this->members->holds($user, $panel, $tenant)) {
            throw self::refused("the user holds no membership of panel '{$panel}' in '{$tenant}'");
        }

        $current = ($this->sessionId)($request);
        $fresh = $this->sessions->switchTo($request, $user, $tenant);
        if ($fresh === '' || hash_equals($current, $fresh)) {
            // Kept, every token the old tenant's pages hold goes on working
            // -- the CSRF token among them, which names no tenant: a stale
            // tab's form would post into the tenant switched to.
            throw new \LogicException('TenantSessions::switchTo() must start a new session, under a new id; it kept the one the request carried.');
        }

        return $fresh;
    }

    /**
     * The tenant whose subtree bounds a switch made from this request's
     * host, or null when nothing does: no realms, or a platform host that
     * names no tenant.
     *
     * A custom domain is its panel's: a session made there is held to a
     * membership of that panel (ADR 0039), and a switch is too.
     */
    private function root(Request $request, string $panel): ?string
    {
        if ($this->realms === null) {
            return null;
        }

        $realm = $this->realms->forRequest($request)
            ?? throw new HttpException(404, 'This host is no realm of the application\'s.', 'tenant_switch_unknown');
        if ($realm->isCustom() && $realm->panel !== $panel) {
            throw self::refused("{$realm->host} is panel '{$realm->panel}''s, not '{$panel}''s");
        }

        return $realm->tenant === '' ? null : $realm->tenant;
    }

    private static function unauthenticated(): HttpException
    {
        return new HttpException(401, 'A tenant is switched by someone signed in.', 'unauthenticated');
    }

    private static function refused(string $why): HttpException
    {
        return new HttpException(403, "The tenant switch is refused: {$why}.", 'tenant_switch_refused');
    }
}
