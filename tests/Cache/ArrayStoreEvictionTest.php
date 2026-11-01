<?php

declare(strict_types=1);

/**
 * The cap evicts the entry written longest ago.
 *
 * sweep() relies on insertion order being oldest first, but re-putting a key
 * kept its original place in the array. A key refreshed a moment ago was
 * therefore the first to go, and an older, untouched one survived it.
 */

namespace Tinvar\Tests\Cache\ArrayStoreEviction;

use Tinvar\Cache\ArrayStore;

it('evicts the oldest write, not a key that was re-put since', function () {
    $store = new ArrayStore(maxEntries: 2);

    $store->put('a', 'a-first', 60);
    $store->put('b', 'b', 60);
    $store->put('a', 'a-refreshed', 60);   // now the newest write
    $store->put('c', 'c', 60);              // over the cap: b is the oldest

    expect($store->get('a'))->toBe(['hit' => true, 'value' => 'a-refreshed'])
        ->and($store->get('b'))->toBe(['hit' => false, 'value' => null])
        ->and($store->get('c'))->toBe(['hit' => true, 'value' => 'c']);
});

it('does not walk every entry for the expired on each put once it is full', function () {
    // The clock is read once per walk, and by nothing else here: no put
    // carries a TTL and nothing is read.
    $walks = 0;
    $store = new ArrayStore(function () use (&$walks): float {
        $walks++;

        return 1000.0;
    }, maxEntries: 1_000);

    for ($i = 0; $i < 2_000; $i++) {
        $store->put("k{$i}", $i);
    }

    // A thousand puts past the cap, each of which walked all thousand
    // entries: a full worker paid that on every miss.
    expect($store->stats()['entries'])->toBe(1_000)
        ->and($walks)->toBeLessThanOrEqual(20);
});
