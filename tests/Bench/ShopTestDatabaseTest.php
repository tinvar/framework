<?php

declare(strict_types=1);

/**
 * The shop's test databases: copies of a template kept while what it is
 * seeded from stays the same. Kept by every file under the shop's database
 * directory, it was kept by the SQLite file `bin/seed` writes there too --
 * and by its -wal and -shm, which change whenever the dev server runs -- so
 * the template was seeded again for nothing, and parallel workers starting
 * while a write was under way each read their own fingerprint and each
 * seeded a template of their own.
 */

namespace Tinvar\Tests\Bench\ShopTestDatabase;

use Tinvar\Bench\Shop;

it('keys the template by what the shop is seeded from, not the database beside it', function () {
    $before = Shop::testFingerprint();
    $probe = Shop::root() . '/database/fingerprint-probe-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        file_put_contents($probe, random_bytes(64));
        file_put_contents("{$probe}-wal", random_bytes(64));

        expect(Shop::testFingerprint())->toBe($before);
    } finally {
        @unlink($probe);
        @unlink("{$probe}-wal");
    }
});
