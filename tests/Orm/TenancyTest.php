<?php

declare(strict_types=1);

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once __DIR__ . '/Fixtures/tenant.php';

function tenantOrm(TenantFixture $fixture): Orm
{
    return new Orm($fixture->connection, new IdentityMap('tenant-test'));
}

it('fails closed when a tenant-scoped model is read with no active tenant', function () {
    $orm = tenantOrm(new TenantFixture());

    expect(fn () => $orm->all(Script::class))->toThrow(NoTenantContext::class)
        ->and(fn () => $orm->find(Script::class, 'anything'))->toThrow(NoTenantContext::class);
});

it('returns only the active tenant\'s rows', function () {
    $fixture = new TenantFixture();
    $orm = tenantOrm($fixture);

    $acme = $orm->actAs('acme', fn (): array => array_map(
        static fn (Script $s): string => $s->name,
        $orm->all(Script::class),
    ));

    expect($acme)->toHaveCount(2)
        ->and($acme)->each->toStartWith('acme-');
});

// The important one: a valid id from another tenant must not resolve.
it('does not resolve an id belonging to another tenant', function () {
    $fixture = new TenantFixture();
    $orm = tenantOrm($fixture);
    $globexId = $fixture->scripts['globex'][0];

    $found = $orm->actAs('acme', fn (): ?object => $orm->find(Script::class, $globexId));

    expect($found)->toBeNull('a foreign id is simply not found');
});

it('scopes relation loads too', function () {
    $fixture = new TenantFixture();
    $orm = tenantOrm($fixture);

    $count = $orm->actAs('acme', function () use ($orm, $fixture): int {
        $script = $orm->find(Script::class, $fixture->scripts['acme'][0]);

        return $script->runs->count();
    });

    expect($count)->toBe(1);
});

it('restores the previous tenant after actAs, even when the body throws', function () {
    $orm = tenantOrm(new TenantFixture());

    $orm->actAs('acme', function () use ($orm): void {
        expect($orm->tenant())->toBe('acme');

        try {
            $orm->actAs('globex', static fn (): never => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        expect($orm->tenant())->toBe('acme', 'the inner switch was undone');
    });

    expect($orm->tenant())->toBe('');
});

describe('acrossTenants', function () {
    it('lifts scoping only for its closure', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);

        $all = $orm->acrossTenants(fn (): array => $orm->all(Script::class));
        expect($all)->toHaveCount(4);

        expect(fn () => $orm->all(Script::class))->toThrow(NoTenantContext::class);
    });

    it('restores scoping when the closure throws', function () {
        $orm = tenantOrm(new TenantFixture());

        try {
            $orm->acrossTenants(static fn (): never => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        expect($orm->isUnscoped())->toBeFalse()
            ->and(fn () => $orm->all(Script::class))->toThrow(NoTenantContext::class);
    });

    it('clears the identity map on exit, so unscoped rows are not served later', function () {
        $fixture = new TenantFixture();
        $orm = tenantOrm($fixture);
        $globexId = $fixture->scripts['globex'][0];

        $orm->acrossTenants(fn (): ?object => $orm->find(Script::class, $globexId));

        $leaked = $orm->actAs('acme', fn (): ?object => $orm->find(Script::class, $globexId));

        expect($leaked)->toBeNull('the unscoped read did not survive into a scoped one');
    });
});

it('leaves a model with no TenantScoped attribute alone', function () {
    $fixture = new Tinvar\Tests\Orm\Fixtures\Fixture(racks: 2, unitsPerRack: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    expect($orm->all(Tinvar\Tests\Orm\Fixtures\Rack::class))->toHaveCount(2);
});
