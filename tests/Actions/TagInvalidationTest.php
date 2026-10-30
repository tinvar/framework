<?php

declare(strict_types=1);

/**
 * TagInvalidation::invalidate(): everything an action's tags do, for a
 * write no declaration covers -- a job's, a scheduled task's, a command's
 * (ADR 0106). In the tenant it runs as, and in no other.
 */

namespace Tinvar\Tests\Actions\TagInvalidation;

use Tinvar\Actions\TagInvalidation;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AuthContext;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\ArrayTagVersions;
use Tinvar\Cache\QueryCache;
use Tinvar\Data\SharedTags;
use Tinvar\Live\Invalidator;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\Topics;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;

const KEY = 'tag-invalidation-test-key-0123456789abc';

/** @return array{Orm, QueryCache, Query, TagInvalidation, MemoryHub, Topics, SharedTags} */
function world(): array
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
    $cache = new QueryCache(new ArrayStore(), new AuthContext());
    $declaration = new Query(cache: '5m', tags: static function (): array {
        return ['stock'];
    });
    foreach (['acme', 'globex', ''] as $tenant) {
        $cache->put('stock.levels', $declaration, [], $tenant, ["{$tenant}-levels"]);
    }
    $hub = new MemoryHub();
    $topics = new Topics(new Signer(KEY, Topics::PURPOSE));
    $holds = new SharedTags(new ArrayTagVersions(), $orm);

    return [$orm, $cache, $declaration, new TagInvalidation($orm, $cache, new Invalidator($hub, $topics, $orm), sharedTags: $holds), $hub, $topics, $holds];
}

it('makes the cached answers of the tenant a job runs as stale, and no other tenant\'s', function () {
    [$orm, $cache, $declaration, $tags, $hub, $topics] = world();

    $orm->actAs('acme', static fn () => $tags->invalidate(['stock']));

    expect($cache->get('stock.levels', $declaration, [], 'acme')['hit'])->toBeFalse()
        ->and($cache->get('stock.levels', $declaration, [], 'globex')['hit'])->toBeTrue()
        ->and($cache->get('stock.levels', $declaration, [], '')['hit'])->toBeTrue()
        ->and(array_column($hub->published, 'topic'))->toBe([$topics->for('acme', 'stock')]);
});

it('invalidates in the tenant it is told, whatever the ORM runs as', function () {
    [, $cache, $declaration, $tags] = world();

    $tags->invalidate(['stock'], 'globex');

    expect($cache->get('stock.levels', $declaration, [], 'globex')['hit'])->toBeFalse()
        ->and($cache->get('stock.levels', $declaration, [], 'acme')['hit'])->toBeTrue();
});

it('moves the once-shared holds of that tenant alone', function () {
    [$orm, , , $tags, , , $holds] = world();
    $before = [$holds->stamp(['stock'], 'acme'), $holds->stamp(['stock'], 'globex')];

    $orm->actAs('acme', static fn () => $tags->invalidate(['stock']));

    expect($holds->stamp(['stock'], 'acme'))->not->toBe($before[0])
        ->and($holds->stamp(['stock'], 'globex'))->toBe($before[1]);
});

it('waits for the commit to tell the clients, and tells none of a rollback', function () {
    [$orm, $cache, $declaration, $tags, $hub] = world();

    try {
        $orm->transaction(static function () use ($tags): void {
            $tags->invalidate(['stock'], 'acme');

            throw new \RuntimeException('the write failed');
        });
    } catch (\RuntimeException) {
    }

    expect($hub->published)->toBe([])
        ->and($cache->get('stock.levels', $declaration, [], 'acme')['hit'])->toBeFalse('forgotten at once, which costs a read and no more');
});
