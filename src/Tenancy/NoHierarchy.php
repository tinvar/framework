<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Orm\TenantSubtrees;

/**
 * Tenancy for tenants side by side: registered with the Orm, so a
 * #[TenantScoped] model can be read as the tenant in force, with no tree to
 * read across. Each tenant is its own subtree, a host's tenant bounds only
 * itself, and withinSubtree() is refused, as with no tree at all.
 */
final readonly class NoHierarchy implements TenantSubtrees
{
    public function hierarchical(): bool
    {
        return false;
    }

    public function ancestors(string $tenant): array
    {
        return [];
    }

    public function subtree(string $root): array
    {
        throw new \LogicException('The application\'s tenants have no hierarchy, so no subtree to read across.');
    }
}
