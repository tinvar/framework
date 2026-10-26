<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Http\Request;
use Tinvar\Http\HostName;

/**
 * The application's hosts, as realms: the platform host sign-in happens on
 * (the authority), the platform's other hosts, and the tenants' custom
 * domains.
 *
 * tinvar-spec.md: "Sign-in happens on the platform host, then redirects
 * back to the custom domain", and "The admin panel is mapped only to
 * platform-owned hosts. No `Domain` record can carry it." The platform's
 * fixed hosts are listed here, by the application, in code. Every other
 * host is looked up -- a tenant's subdomain of the platform's domain, a
 * custom domain from the application's records -- and an answer that would
 * put a platform panel on a custom domain, or stand in for a listed host,
 * is refused, as if there were no answer at all.
 */
final class Realms
{
    /** @var array<string, Realm> host => one of the platform's own */
    private array $platform = [];

    /** @var array<string, true> */
    private readonly array $platformPanels;

    /**
     * @param Realm $authority the platform host sign-in happens on, and handoffs start from
     * @param list<Realm> $hosts the platform's other hosts: a panel's own host, say
     * @param (\Closure(string): ?Realm)|null $lookup the realm a normalized host beyond those
     *        listed is: a tenant's subdomain of the platform's domain, or a custom domain from
     *        the application's records -- verified and active ones only, the records
     *        CustomDomainVerification deactivates a domain in when its DNS moves -- or null
     * @param list<string> $platformPanels panels only the platform's own hosts may carry, as admin
     */
    public function __construct(
        public readonly Realm $authority,
        array $hosts = [],
        private readonly ?\Closure $lookup = null,
        array $platformPanels = [],
    ) {
        foreach ([$authority, ...$hosts] as $realm) {
            if ($realm->isCustom()) {
                throw new \InvalidArgumentException(
                    "{$realm->origin} is a custom domain; the platform's own hosts are listed, and custom domains looked up.",
                );
            }
            if (isset($this->platform[$realm->host])) {
                throw new \InvalidArgumentException("Two realms are on {$realm->host}; a host is one realm.");
            }
            $this->platform[$realm->host] = $realm;
        }

        $this->platformPanels = array_fill_keys($platformPanels, true);
    }

    /**
     * Whether a host can be looked up, and so may be a tenant's custom
     * domain: then a handoff has staff and memberships to hold users to.
     */
    public function looksUp(): bool
    {
        return $this->lookup !== null;
    }

    public function isPlatformPanel(string $panel): bool
    {
        return isset($this->platformPanels[$panel]);
    }

    /**
     * The panels only the platform's own hosts may carry.
     *
     * @return list<string>
     */
    public function platformPanels(): array
    {
        return array_map('strval', array_keys($this->platformPanels));
    }

    public function isAuthority(Realm $realm): bool
    {
        return $realm->host === $this->authority->host;
    }

    /**
     * The realm a normalized host is, or null for one the application does
     * not serve.
     *
     * The listed hosts first, so no record can stand in for one of them. An
     * answer for a host other than the one asked about, or a custom domain
     * carrying a panel only the platform's hosts may carry, is refused: it
     * is either a lookup that answered the wrong question or a record
     * nobody should have been able to save, and both fail closed.
     */
    public function forHost(string $host): ?Realm
    {
        if (isset($this->platform[$host])) {
            return $this->platform[$host];
        }

        $realm = $this->lookup === null ? null : ($this->lookup)($host);
        if ($realm === null || $realm->host !== $host || ($realm->isCustom() && $this->isPlatformPanel($realm->panel))) {
            return null;
        }

        return $realm;
    }

    /** The realm a request's Host header names, read as HostName reads it -- never X-Forwarded-Host. */
    public function forRequest(Request $request): ?Realm
    {
        $host = HostName::normalize($request->header('host') ?? '');

        return $host === null ? null : $this->forHost($host);
    }

    /** The realm an origin is exactly, or null: its host's realm, at that scheme and port. */
    public function forOrigin(string $origin): ?Realm
    {
        $host = Realm::hostOf($origin);
        $realm = $host === null ? null : $this->forHost($host);

        return $realm !== null && $realm->origin === $origin ? $realm : null;
    }
}
