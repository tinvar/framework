<?php

declare(strict_types=1);

/**
 * What a query read, recorded whether or not anything caches its answer.
 *
 * A render's live token is scoped to the tags its queries declare, and an
 * application that caches nothing -- the shop's render channel has no cache
 * -- still wants its pages told when what they read changes. Recording only
 * where a cache was configured left every such render reading nothing.
 */

namespace Tinvar\Tests\Live\QueryTags;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Query\Fixtures\CachedSummary;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';

it('records the tags a query declares with no cache to keep its answer in', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new QueryRegistry(static fn (string $c): object => new $c());
    $registry->register(CachedSummary::class);
    $runner = new QueryRunner(registry: $registry, orm: new Orm($fixture->connection, new IdentityMap('test')), gate: new AllowAllGate());

    $rack = $fixture->ids['racks'][0];
    $runner->run([['id' => 0, 'name' => 'racks.cached', 'args' => ['rack' => $rack]]], ['racks.cached']);

    // Namespaced by tenant, as QueryCache namespaces them: the one tenant
    // here is none.
    expect($runner->tagsRead())->toBe(["\0rack:{$rack}"]);
});
