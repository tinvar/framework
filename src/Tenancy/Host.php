<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Http\HostName;
use Tinvar\Http\Request;

/**
 * The tenant a request's host belongs to: `#[TenantFrom(Host::class)]`.
 *
 * Read from the Host header the server received, never X-Forwarded-Host:
 * that is whatever the client chose to send unless a proxy you control
 * overwrote it, and the proxy in front of the application is what decides
 * which hosts reach it at all.
 *
 * A host is a claim, not a credential -- anyone can send any Host header --
 * so what it settles is which tenant's data the request may see, and no
 * more. "The tenant resolved from a host only constrains": a session still
 * has to hold a membership there to see anything a panel guards, and a
 * request already running as a tenant -- the session's -- goes on as that
 * one when it is the host's or below it (SubtreeSource), and is refused
 * otherwise.
 *
 * Normalized before the application is asked, as HostName normalizes every
 * host: lowercase, without its port or a trailing dot, so
 * `Acme.Shop.test.:443` and `acme.shop.test` are one tenant and not two
 * lookups an attacker can tell apart. Anything that is not a host name --
 * an address literal, a stray character -- belongs to no tenant.
 */
final readonly class Host implements SubtreeSource
{
    public function __construct(private TenantHosts $hosts) {}

    public function tenantFor(Request $request): ?string
    {
        $host = HostName::normalize($request->header('host') ?? '');
        if ($host === null) {
            return null;
        }

        $tenant = $this->hosts->tenantForHost($host);

        return $tenant === '' ? null : $tenant;
    }

    /**
     * The host name a Host header names, normalized; null for anything that
     * is not one. HostName's, kept here for the code that called it here.
     */
    public static function normalize(string $header): ?string
    {
        return HostName::normalize($header);
    }
}
