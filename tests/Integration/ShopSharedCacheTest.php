<?php

declare(strict_types=1);

/**
 * The shop's cached queries kept in one store every server reaches, when
 * SHOP_CACHE_STORE names one: an action's forget on one server is a miss on
 * the others, rather than an answer from before the write served there
 * until its TTL runs out.
 */

namespace Tinvar\Tests\Integration\ShopSharedCache;

use Shop\Database\Seeder;
use Shop\Kernel;
use Tinvar\Attributes\Query;
use Tinvar\Cache\ApcuStore;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\PdoStore;
use Tinvar\Orm\PdoConnection;

const KEY = 'shop-shared-cache-test-key-32-bytes!!!';

/** A scratch directory, removed when the process ends. */
function directory(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-shared-cache-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() !== $owner) {
            return;
        }
        foreach (glob("{$directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    });

    return $directory;
}

/**
 * $work with the environment set as given -- null unsets -- and put back
 * after.
 *
 * @template T
 * @param array<string, string|null> $env
 * @param \Closure(): T $work
 * @return T
 */
function withEnv(array $env, \Closure $work): mixed
{
    $previous = [];
    foreach ($env as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }

    try {
        return $work();
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

describe('SHOP_CACHE_STORE', function () {
    it('names nothing unless it is set, and the shop caches in APCu or the worker as before', function () {
        withEnv(['SHOP_CACHE_STORE' => null], function (): void {
            expect(Kernel::sharedCacheStore())->toBeNull()
                ->and(Kernel::cacheStore())->toBeInstanceOf(ApcuStore::available() ? ApcuStore::class : ArrayStore::class);
        });
    });

    it('keeps the entries in tables in the database a PDO DSN names', function () {
        withEnv(['SHOP_CACHE_STORE' => 'sqlite::memory:'], function (): void {
            expect(Kernel::sharedCacheStore())->toBeInstanceOf(PdoStore::class)
                ->and(Kernel::cacheStore())->toBeInstanceOf(PdoStore::class);
        });
    });

    // Falling back to each server's own cache is a forget that reaches one
    // server of many; and the value may carry a password, so it is never
    // repeated in the refusal.
    it('refuses what it cannot read, rather than caching alone, and never repeats it', function () {
        foreach (['postgres://shop:secret@db/shop', 'pgsql:', 'redis://:secret@cache:6379', 'secret'] as $value) {
            withEnv(['SHOP_CACHE_STORE' => $value], function (): void {
                try {
                    Kernel::sharedCacheStore();
                    $message = 'accepted';
                } catch (\RuntimeException $e) {
                    $message = $e->getMessage();
                }

                expect($message)->toStartWith('SHOP_CACHE_STORE')->and($message)->not->toContain('secret');
            });
        }
    });

    it('has bin/seed make the tables, once, where it names a database', function () {
        $directory = directory();

        withEnv(['SHOP_CACHE_STORE' => "sqlite:{$directory}/cache.sqlite"], function () use ($directory): void {
            $shared = Kernel::sharedCacheStore();
            \assert($shared instanceof PdoStore);
            $shared->install();
            $shared->install();

            expect(new PdoStore(new \PDO("sqlite:{$directory}/cache.sqlite"))->count())->toBe(0);
        });
    });
});

// Two servers, each with a database of its own -- and one cache, named,
// that both reach: what one forgets, the other misses.
it('forgets on every server what one server forgets', function () {
    $directory = directory();
    $declaration = new Query(cache: '5m', tags: static function (string $product): array {
        return ['product:' . $product];
    });

    [$first, $second] = withEnv(['SHOP_CACHE_STORE' => "sqlite:{$directory}/cache.sqlite"], static function () use ($directory): array {
        $servers = [];
        foreach (['first', 'second'] as $name) {
            $database = "{$directory}/{$name}.sqlite";
            new Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 1);
            $servers[] = Kernel::fromEnvironment("{$directory}/no-renderer.sock", key: KEY, database: $database);
        }
        $shared = Kernel::sharedCacheStore();
        \assert($shared instanceof PdoStore);
        $shared->install();

        return $servers;
    });

    $first->kernel()->cache->put('products.cached', $declaration, ['product' => 'p1'], '', ['stock' => 4]);
    expect($second->kernel()->cache->get('products.cached', $declaration, ['product' => 'p1'], ''))
        ->toBe(['hit' => true, 'value' => ['stock' => 4]]);

    $second->kernel()->cache->forgetTags(['product:p1'], '', null);
    expect($first->kernel()->cache->get('products.cached', $declaration, ['product' => 'p1'], '')['hit'])->toBeFalse();
});
