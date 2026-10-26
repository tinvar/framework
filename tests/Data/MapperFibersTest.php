<?php

declare(strict_types=1);

/**
 * Mapping inside a render, where the scheduler interleaves query calls.
 *
 * Each call runs in its own fiber and suspends mid-mapping when it reads a
 * relation, so the next call starts mapping while the first is still inside
 * it. The mapper's cycle and depth guard has to see only its own call.
 */

namespace Tinvar\Tests\Data\MapperFibers;

use Tinvar\Data\Data;
use Tinvar\Data\DataMappingException;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

final readonly class UnitData extends Data
{
    public function __construct(public string $name, public int $position) {}
}

final readonly class RackData extends Data
{
    /** @param list<UnitData> $units */
    public function __construct(public string $name, public array $units) {}
}

final readonly class LoopData extends Data
{
    public function __construct(public string $name, public ?LoopData $next = null) {}
}

/** A source that refers back to itself, so mapping it never bottoms out. */
final class Loop
{
    public string $name = 'loop';
    public Loop $next;

    public function __construct()
    {
        $this->next = $this;
    }
}

it('maps more calls in one tick than the depth limit allows levels', function () {
    $fixture = new Fixture(racks: 40, unitsPerRack: 2);
    $orm = new Orm($fixture->connection);
    $racks = $orm->all(Rack::class);

    $tasks = [];
    foreach ($racks as $i => $rack) {
        $tasks[$i] = static fn (): RackData => RackData::from($rack);
    }
    $results = $orm->beginRender()->run($tasks);

    $failures = array_map(
        static fn (\Throwable $e): string => $e->getMessage(),
        array_filter($results, static fn (mixed $r): bool => $r instanceof \Throwable),
    );

    expect($failures)->toBe([])
        ->and($results[39]->units)->toHaveCount(2);
});

it('maps the same row in two calls of one tick without calling it a cycle', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 2);
    $orm = new Orm($fixture->connection);
    $rack = $orm->all(Rack::class)[0];

    $results = $orm->beginRender()->run([
        'a' => static fn (): RackData => RackData::from($rack),
        'b' => static fn (): RackData => RackData::from($rack),
    ]);

    expect($results['a'])->toBeInstanceOf(RackData::class)
        ->and($results['b'])->toBeInstanceOf(RackData::class);
});

it('still refuses a cycle inside one fiber', function () {
    $fiber = new \Fiber(static fn (): LoopData => LoopData::from(new Loop()));

    expect(fn () => $fiber->start())->toThrow(DataMappingException::class, 'cyclic');
});

it('leaves no mapping state behind in the main context', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);
    $orm = new Orm($fixture->connection);
    $racks = $orm->all(Rack::class);

    $orm->beginRender()->run([
        static fn (): RackData => RackData::from($racks[0]),
        static fn (): RackData => RackData::from($racks[1]),
    ]);
    $orm->endRender();

    expect(RackData::from($racks[0])->units)->toHaveCount(1);
});
