<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

/**
 * The application's tenants, as work with no tenant of its own needs them:
 * a command checks the tenant it was named against it, and forEachTenant()
 * visits each one.
 */
interface TenantDirectory
{
    public function has(string $tenant): bool;

    /** @return iterable<string> every tenant, in a stable order */
    public function all(): iterable;
}
