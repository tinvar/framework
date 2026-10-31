<?php

declare(strict_types=1);

/**
 * A user's memberships at or below a tenant (SubtreeMemberships): the
 * switcher's list, and the handoff's one tenant to land in.
 */

namespace Tinvar\Tests\Auth\SubtreeMemberships;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\SubtreeMemberships;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/policies.php';

/** Lists acme, globex and initech, and holds a membership in globex and initech; counts what it is asked. */
final class Listed implements Memberships, TenantMemberships
{
    /** @var list<string> */
    public array $asked = [];

    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        $this->asked[] = $tenant;

        return \in_array($tenant, ['globex', 'initech'], true) ? new Membership('member') : null;
    }

    public function tenantsOf(Authenticatable $user, string $panel): array
    {
        return ['acme', 'globex', 'initech'];
    }
}

// A handoff lands in the first tenant the user belongs to below the realm's,
// and asked Memberships to confirm every one listed to take the first.
it('finds the first tenant a membership is held in, asking no further than it', function () {
    $memberships = new Listed();
    $members = new SubtreeMemberships(new Orm(new TenantFixture()->connection, new IdentityMap('test'), Tenancy::flat()), $memberships);
    $user = new User('u-1');

    expect($members->first($user, 'staff', null))->toBe('globex')
        ->and($memberships->asked)->toBe(['acme', 'globex'])
        ->and($members->tenants($user, 'staff', null))->toBe(['globex', 'initech']);
});
