<?php

declare(strict_types=1);

/**
 * Once-shared values (ADR 0065): `#[Shared(once: true)]` declared and
 * refused where it cannot hold, and a render's SharedReads answering a once
 * key as a once field -- with the hold minted for it, or held and never
 * produced.
 */

use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Data\SharedReads;
use Tinvar\Data\SharedRegistry;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\RouteKind;
use Tinvar\Tests\Data\Fixtures\Shared\BadTagOnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\BadUntilOnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\BuiltWithRequestOnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\OnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\PageOnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\PageWithGreetingData;
use Tinvar\Tests\Data\Fixtures\Shared\ProvidedOnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\Reads;
use Tinvar\Tests\Data\Fixtures\Shared\RequestOnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\TagsWithoutOnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\TooLongOnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\UntilWithoutOnceShared;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/shared-once.php';

/**
 * Every OnceShared key read in one render, as the renderer reads them.
 *
 * @param list<string> $held
 * @param (\Closure(string, int): string)|null $hold
 * @return array{values: array<string, mixed>, reads: Reads}
 */
function readOnce(array $held = [], ?\Closure $hold = null, ?Request $request = null): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $reads = new Reads();
    $reads->rack = ($request ?? new Request('GET', '/', ['x-who' => 'Ada']))->header('x-who') ?? '';
    $registry = new SharedRegistry([OnceShared::class]);
    $keys = ['motd', 'pinned', 'broken', 'plain'];

    $shared = new SharedReads(
        registry: $registry,
        keys: array_fill_keys($keys, true),
        resolver: static fn (string $class): object => new $class($reads),
        request: $request ?? new Request('GET', '/', ['x-who' => 'Ada']),
        page: new CompiledRoute(RouteKind::Page, PageWithGreetingData::class, '/', 'home', 'home', [], [], [], []),
        parameters: [],
        orm: $orm,
        auth: new AuthContext(),
        held: $held,
        hold: $hold,
    );
    $runner = new QueryRunner(new QueryRegistry(static fn (string $class): object => new $class()), $orm, new AllowAllGate());

    $values = [];
    foreach ([$keys, $keys] as $tick) {
        $calls = array_map(
            static fn (int $i, string $key): array => ['id' => $i, 'name' => SharedReads::PREFIX . $key, 'args' => []],
            array_keys($tick),
            $tick,
        );
        foreach ($runner->run($calls, [], $shared) as $result) {
            $values[$tick[$result['id']]] = $result['value'] ?? $result['error']['code'];
        }
    }

    return ['values' => $values, 'reads' => $reads];
}

describe('the registry', function () {
    it('reads a once key\'s time and tags, a day when it names none', function () {
        $registry = new SharedRegistry([OnceShared::class]);

        expect($registry->onceOf('motd'))->toBe(['ttl' => 600, 'tags' => ['notes', 'alerts']])
            ->and($registry->onceOf('pinned'))->toBe(['ttl' => SharedRegistry::MAX_HOLD, 'tags' => []])
            ->and($registry->onceOf('plain'))->toBeNull();
    });

    it('refuses what cannot be kept', function (string $class, string $message) {
        expect(fn () => new SharedRegistry([$class]))->toThrow(\InvalidArgumentException::class, $message);
    })->with([
        'until without once' => [UntilWithoutOnceShared::class, 'without `once: true`'],
        'tags without once' => [TagsWithoutOnceShared::class, 'without `once: true`'],
        'longer than a day' => [TooLongOnceShared::class, 'longer than the day'],
        'no duration' => [BadUntilOnceShared::class, 'is not a duration'],
        'two tags in one' => [BadTagOnceShared::class, 'not one tag'],
        'told the page' => [PageOnceShared::class, 'takes a DataContext'],
        'handed the request, and its URL' => [RequestOnceShared::class, 'takes the Request'],
        'built with the request' => [BuiltWithRequestOnceShared::class, 'built with the Request'],
        'a ProvidesData' => [ProvidedOnceShared::class, 'a ProvidesData'],
    ]);
});

describe('a render', function () {
    it('sends a once key as a once field, with the hold minted for it', function () {
        $minted = [];
        ['values' => $values, 'reads' => $reads] = readOnce(hold: static function (string $key, int $ttl) use (&$minted): string {
            $minted[] = [$key, $ttl];

            return "hold-for-{$key}";
        });

        expect($values['motd'])->toBe([
            '$once' => '$shared.motd',
            'ttl' => 600,
            'tags' => ['notes', 'alerts'],
            'value' => ['text' => 'hello'],
            'hold' => 'hold-for-motd',
        ])
            ->and($values['pinned']['value'])->toBe(['text' => 'pinned'])
            ->and($values['plain'])->toBe(['text' => 'plain'])
            // Produced, and its hold minted, once for the render however
            // many ticks read it.
            ->and($reads->calls)->toBe(['motd', 'pinned', 'broken', 'plain'])
            ->and($minted)->toBe([['motd', 600], ['pinned', SharedRegistry::MAX_HOLD]]);
    });

    it('sends a once key to be shown and not kept where no hold can be minted', function () {
        ['values' => $values] = readOnce();

        expect($values['motd'])->toMatchArray(['$once' => '$shared.motd', 'keep' => false, 'value' => ['text' => 'hello']])
            ->and($values['motd'])->not->toHaveKey('hold');
    });

    it('wraps a null value as it wraps any other', function () {
        ['values' => $values] = readOnce(hold: static fn (): string => 'h', request: new Request('GET', '/'));

        expect($values['pinned'])->toMatchArray(['$once' => '$shared.pinned', 'value' => null, 'hold' => 'h']);
    });

    it('answers a held once key as held, and never produces it', function () {
        ['values' => $values, 'reads' => $reads] = readOnce(held: ['motd', 'broken'], hold: static fn (): string => 'h');

        expect($values['motd'])->toBe(['$once' => '$shared.motd', 'held' => true])
            ->and($values['broken'])->toBe(['$once' => '$shared.broken', 'held' => true])
            ->and($values['pinned'])->toHaveKey('value')
            ->and($reads->calls)->toBe(['pinned', 'plain']);
    });

    it('produces a key the held list names that is not once', function () {
        ['values' => $values, 'reads' => $reads] = readOnce(held: ['plain']);

        expect($values['plain'])->toBe(['text' => 'plain'])
            ->and($reads->calls)->toContain('plain');
    });

    it('fails a once key that throws as any key does, minting nothing for it', function () {
        $minted = [];
        ['values' => $values] = readOnce(hold: static function (string $key) use (&$minted): string {
            $minted[] = $key;

            return 'h';
        });

        expect($values['broken'])->toBe('query_failed')
            ->and($minted)->not->toContain('broken');
    });
});
