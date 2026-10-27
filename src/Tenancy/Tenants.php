<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Orm\Orm;

/**
 * forEachTenant(): the spec's way for work that runs with no tenant to do
 * tenant-scoped work.
 *
 * "Scheduled tasks and migrations run with no tenant, so tenant-scoped work
 * in them must use forEachTenant() or acrossTenants()", and "the map is
 * cleared at every ... forEachTenant iteration". Each tenant's turn runs
 * inside Orm::actAs() with the identity map cleared before it, so no row
 * read for one tenant is served to the next.
 *
 * Only from no tenant. Code already running as a tenant -- a request, a
 * job -- reaching every other tenant's rows is the widening the spec keeps
 * behind acrossTenants(), the escape hatch it requires to be explicit.
 *
 * One tenant's failure does not stop the rest: each is owed its turn, and
 * the first failure is rethrown once all have had it.
 */
final readonly class Tenants
{
    public function __construct(private Orm $orm, private TenantDirectory $directory) {}

    /** @param \Closure(string): void $work given each tenant, running as it */
    public function forEachTenant(\Closure $work): void
    {
        if ($this->orm->tenant() !== '' || $this->orm->isUnscoped()) {
            throw new \LogicException(
                'forEachTenant() is for work that runs with no tenant -- a scheduled task, a migration. Running as a tenant, or inside acrossTenants(), it would reach every other tenant.',
            );
        }

        $failure = null;
        foreach ($this->directory->all() as $tenant) {
            if ($tenant === '') {
                continue;
            }

            $this->orm->identityMap()->flush();
            try {
                $this->orm->actAs($tenant, static fn () => $work($tenant));
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        $this->orm->identityMap()->flush();

        if ($failure !== null) {
            throw $failure;
        }
    }
}
