<?php

declare(strict_types=1);

namespace Tinvar\Tests\Cache\Surface;

use Tinvar\Attributes\Query;
use Tinvar\Auth\AuthContext;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\QueryCache;

it('flushes every entry and every tag index with it', function () {
    $store = new ArrayStore();
    $store->put('k1', 'one', 60, ['racks']);
    $store->put('k2', 'two', null, ['racks', 'sites']);

    $store->flush();

    expect($store->get('k1')['hit'])->toBeFalse()
        ->and($store->get('k2')['hit'])->toBeFalse()
        ->and($store->stats()['entries'])->toBe(0)
        ->and($store->stats()['tags'])->toBe(0)
        ->and($store->forgetTags(['racks', 'sites']))->toBe(0);
});

// A query without `cache:` never reaches the store: not to read, not to write.
it('leaves the store alone for a query that does not cache', function () {
    $store = new ArrayStore();
    $cache = new QueryCache($store, new AuthContext());
    $declaration = new Query(tags: static fn (): array => ['racks']);

    $cache->put('racks.list', $declaration, ['page' => 1], 'acme', ['r1']);

    expect($cache->get('racks.list', $declaration, ['page' => 1], 'acme'))->toBe(['hit' => false, 'value' => null])
        ->and($store->stats())->toBe(['hits' => 0, 'misses' => 0, 'entries' => 0, 'tags' => 0]);
});
