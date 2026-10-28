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

it('refuses a field the class does not declare', function () {
    expect(fn () => (new RackData('r1', 'R-14', 42))->with(['secret' => 'x']))->toThrow(\Error::class, 'dynamic property');
});

it('cannot reach a subclass\'s private field', function () {
    expect(fn () => (new RackData('r1', 'R-14', 42))->with(['note' => 'changed']))->toThrow(\Error::class, 'private');
});
