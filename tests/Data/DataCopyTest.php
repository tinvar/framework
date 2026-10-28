<?php

declare(strict_types=1);

/**
 * "readonly Data objects support clone($data, ['name' => $new]) for copies"
 * (tinvar-spec.md, "Data boundary"). PHP 8.5 refuses that clone outside the
 * class, so the copy is Data::with(), the same clone made from inside.
 */

namespace Tinvar\Tests\Data\Copy;

use Tinvar\Data\Data;
use Tinvar\Data\Encoder;

final readonly class RackData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public int $heightU,
        private string $note = 'kept',
        protected string $origin = 'loader',
    ) {}

    public function note(): string
    {
        return $this->note;
    }
}

it('cannot be copied with the spec\'s clone outside the class, which is why with() exists', function () {
    $rack = new RackData('r1', 'R-14', 42);

    expect(fn () => clone($rack, ['name' => 'R-15']))->toThrow(\Error::class, 'protected(set) readonly');
});

it('copies with the fields named changed and leaves the original as it was', function () {
    $rack = new RackData('r1', 'R-14', 42);
    $copy = $rack->with(['name' => 'R-15', 'heightU' => 48]);

    expect($copy)->toBeInstanceOf(RackData::class)
        ->not->toBe($rack)
        ->and([$copy->id, $copy->name, $copy->heightU, $copy->note()])->toBe(['r1', 'R-15', 48, 'kept'])
        ->and([$rack->name, $rack->heightU])->toBe(['R-14', 42])
        ->and((new Encoder())->encode($copy))->toBe(['id' => 'r1', 'name' => 'R-15', 'heightU' => 48]);
});

it('holds each value to its field\'s type', function () {
    expect(fn () => (new RackData('r1', 'R-14', 42))->with(['heightU' => 'tall']))->toThrow(\TypeError::class);
});

it('refuses anything but a public field, before copying', function (array $changes) {
    expect(fn () => (new RackData('r1', 'R-14', 42))->with($changes))->toThrow(\InvalidArgumentException::class, 'no public field');
})->with([
    'a field the class does not declare' => [['secret' => 'x']],
    'a private field' => [['note' => 'changed']],
    // Reachable from Data's own scope, as no caller outside the class is.
    'a protected field' => [['origin' => 'client']],
    'a list of values' => [['R-15']],
]);
