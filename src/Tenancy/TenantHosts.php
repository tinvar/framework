<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

/**
 * The application's map from a host to the tenant it belongs to: custom
 * domains, subdomains of the platform's own domain, whatever the application
 * keeps. Host asks it; Subdomains is one way to answer.
 *
 * The host is already normalized -- lowercase, no port, no trailing dot --
 * and is a syntactically valid name, so an implementation can look it up
 * as is. Null for a host that belongs to no tenant, which is refused.
 */
interface TenantHosts
{
    public function tenantForHost(string $host): ?string;
}
