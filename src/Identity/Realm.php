<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Http\HostName;

/**
 * One host's authentication realm: the origin a session made there belongs
 * to, the tenant and panel that session is for, and whether the platform
 * owns the host or a tenant does.
 *
 * tinvar-spec.md, "Identity across domains": "Each custom domain is an
 * authentication realm. A session created on it can only hold memberships
 * within that domain's tenant subtree." A browser keeps the cookies of two
 * registrable domains apart, and the session cookie is `__Host-`, so it is
 * not shared even between two hosts of one domain: every host is a realm of
 * its own, and a session reaches another only by a handoff (HandoffEndpoint)
 * that ends in a new session there.
 *
 * Two kinds. A **platform** host is the platform's own -- where sign-in
 * happens, a panel's own host such as `admin.example.com` -- and may carry
 * any panel. A **domain** is a tenant's custom domain, whose DNS a tenant (a
 * reseller, say) controls: it always names its tenant, and the panel its
 * sessions are for, so a handoff to it can be held to a membership there.
 *
 * The tenant a realm names is the root of its subtree, by the tenant tree
 * registered with the Orm (tinvar/tenancy): a session made there may run as
 * that tenant or any below it where its user holds a membership, and a
 * tenant switch there stays below it. With no tree, the subtree is the
 * tenant alone; with no tenancy, a realm's tenant is no tenant's, and a
 * handoff's code names none (TenantClaim).
 */
final readonly class Realm
{
    /** The host, normalized: what a request's Host header is compared with. */
    public string $host;

    private function __construct(
        public string $origin,
        public string $tenant,
        public string $panel,
        public bool $platform,
    ) {
        $this->host = self::hostOf($origin) ?? throw new \InvalidArgumentException(
            "'{$origin}' is not an origin a realm can be: https:// and a host name, with a port only when it is not "
            . 'the default; http:// only for localhost, which never leaves the machine.',
        );
    }

    /**
     * One of the platform's own hosts.
     *
     * @param string $origin as a browser serializes it, `https://admin.example.com`
     * @param string $tenant the tenant a session made here runs as, '' for none
     * @param string $panel the panel a handoff here is into, '' for none
     */
    public static function platform(string $origin, string $tenant = '', string $panel = ''): self
    {
        return new self($origin, $tenant, $panel, true);
    }

    /**
     * A tenant's custom domain. Always a tenant's and always a panel's: a
     * session made on a domain someone else controls is held to a
     * membership of that panel in that tenant, by the handoff that makes it.
     */
    public static function domain(string $origin, string $tenant, string $panel): self
    {
        if ($tenant === '') {
            throw new \InvalidArgumentException("The custom domain {$origin} names no tenant; a custom domain is always a tenant's.");
        }
        if ($panel === '') {
            throw new \InvalidArgumentException(
                "The custom domain {$origin} names no panel; a session made there is held to a membership, and a membership is of a panel.",
            );
        }

        return new self($origin, $tenant, $panel, false);
    }

    /** Whether a tenant, not the platform, controls the host. */
    public function isCustom(): bool
    {
        return !$this->platform;
    }

    /** An absolute URL on this realm, for a path and query on it. */
    public function url(string $pathAndQuery): string
    {
        if (!str_starts_with($pathAndQuery, '/') || str_starts_with($pathAndQuery, '//')) {
            throw new \InvalidArgumentException('A URL on a realm is built from a root-relative path.');
        }

        return $this->origin . $pathAndQuery;
    }

    /**
     * The host an origin names, or null when it is not one a realm may be.
     *
     * One spelling per origin, the one a browser serializes: lowercase, no
     * trailing dot, no path, and no port when it is the scheme's default --
     * an origin compared as a string must not have a second string that
     * means it. https, because a handoff code travels in the URL and a
     * plain-http hop would hand it to the network; http only for
     * `localhost` and its subdomains, which resolve to this machine.
     */
    public static function hostOf(string $origin): ?string
    {
        if (preg_match('#^(https?)://([^/:?\#@]+)(?::([1-9][0-9]{0,4}))?\z#', $origin, $m) !== 1) {
            return null;
        }
        [, $scheme, $host] = $m;
        $port = $m[3] ?? '';

        if (HostName::normalize($host) !== $host || ($port !== '' && (int) $port > 65535)) {
            return null;
        }
        if (($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80')) {
            return null;
        }
        if ($scheme === 'http' && $host !== 'localhost' && !str_ends_with($host, '.localhost')) {
            return null;
        }

        return $host;
    }
}
