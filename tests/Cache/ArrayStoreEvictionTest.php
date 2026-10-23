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
