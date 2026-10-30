<?php

declare(strict_types=1);

/**
 * TagInvalidation::invalidate(): everything an action's tags do, for a
 * write no declaration covers -- a job's, a scheduled task's, a command's
 * (ADR 0106). In the tenant it runs as, and in no other.
 */

namespace Tinvar\Tests\Actions\TagInvalidation;

use Tinvar\Actions\TagInvalidation;
use Tinvar\Attributes\Import;
use Tinvar\Attributes\NoAuthorization;
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
use Tinvar\Transfers\TransferRegistry;
use Tinvar\Validation\FormRequest;

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

// ADR 0106: a declared tag names the record a write changed by a
// placeholder an endpoint's path or an import's argument fills.
describe('a tag with a placeholder', function () {
    it('is filled from a model\'s key, an enum, a scalar, or nothing', function () {
        expect(TagInvalidation::filled(['rack:{rack}', 'slot:{rack}:{n}', 'kind:{kind}', 'flag:{on}', 'plain', 'none:{gone}'], [
            'rack' => 'r-1',
            'n' => 7,
            'kind' => Kind::Shelf,
            'on' => true,
        ]))->toBe(['rack:r-1', 'slot:r-1:7', 'kind:shelf', 'flag:1', 'plain', 'none:']);
    });

    it('refuses a value carrying what no tag may', function (string $value) {
        expect(fn () => TagInvalidation::filled(['rack:{rack}'], ['rack' => $value]))
            ->toThrow(\InvalidArgumentException::class, 'cannot be carried by a tag');
    })->with(['a comma' => ['a,b'], 'a NUL' => ["a\0b"], 'a line break' => ["a\r\nb"], 'DEL' => ["a\x7fb"]]);

    it('names its placeholders once each', function () {
        expect(TagInvalidation::placeholders('slot:{rack}:{n}:{rack}'))->toBe(['rack', 'n'])
            ->and(TagInvalidation::placeholders('racks'))->toBe([])
            ->and(TagInvalidation::placeholders('odd:{1x}'))->toBe([]);
    });

    it('refuses, at an import\'s registration, a placeholder it has no argument for', function () {
        expect(fn () => new TransferRegistry()->register(MisnamedImport::class))
            ->toThrow(\InvalidArgumentException::class, "has no parameter named 'shelf'");
    });
});

enum Kind: string
{
    case Shelf = 'shelf';
}

final readonly class ShelfRow extends FormRequest
{
    public function __construct(public string $sku) {}
}

#[Import(name: 'shelves', revalidates: ['shelf:{shelf}']), NoAuthorization(reason: 'test')]
final readonly class MisnamedImport
{
    public function __invoke(ShelfRow $row, string $aisle): void {}
}
