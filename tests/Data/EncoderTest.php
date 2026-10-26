<?php

declare(strict_types=1);

use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;

final readonly class UnitFixture extends Data
{
    public function __construct(
        public string $id,
        public int $position,
        public string $name,
    ) {}
}

final readonly class RackFixture extends Data
{
    /**
     * A computed field is assigned in the constructor. A readonly class may not
     * declare hooked properties, so `unitCount` cannot be a get hook (ADR 0004).
     */
    public int $unitCount;

    public function __construct(
        public string $id,
        public string $name,
        /** @var list<UnitFixture> */
        public array $units,
        public ?UnitFixture $primary = null,
    ) {
        $this->unitCount = count($units);
    }
}

final readonly class SecretiveFixture extends Data
{
    public string $neverSet;   // uninitialized on purpose

    public function __construct(
        public string $shown = 'yes',
        private string $hidden = 'no',        // @phpstan-ignore-line
        protected string $alsoHidden = 'no',  // @phpstan-ignore-line
    ) {}
}

final readonly class LazyFixture extends Data
{
    public function __construct(public Lazy $units) {}
}

/** Stands in for an ORM model: an ordinary object that must never cross. */
final class ModelFixture
{
    public string $passwordHash = '$2y$10$notforthebrowser';
}

enum Status: string
{
    case Active = 'active';
}

beforeEach(fn () => $this->encoder = new Encoder());

it('encodes a Data object to a map of its public properties', function () {
    expect($this->encoder->encode(new UnitFixture('0192f3a1', 1, 'sw-core-01')))
        ->toBe(['id' => '0192f3a1', 'position' => 1, 'name' => 'sw-core-01']);
});

it('encodes nested Data objects and lists of them', function () {
    $rack = new RackFixture('r1', 'R-14', [
        new UnitFixture('u1', 1, 'sw-core-01'),
        new UnitFixture('u2', 2, 'sw-core-02'),
    ]);

    // Fields appear in declaration order, which is what the payload rows and
    // the generated TypeScript both follow.
    expect($this->encoder->encode($rack))->toBe([
        'unitCount' => 2,
        'id' => 'r1',
        'name' => 'R-14',
        'units' => [
            ['id' => 'u1', 'position' => 1, 'name' => 'sw-core-01'],
            ['id' => 'u2', 'position' => 2, 'name' => 'sw-core-02'],
        ],
        'primary' => null,
    ]);
});

it('includes a computed field assigned in the constructor', function () {
    $encoded = $this->encoder->encode(new RackFixture('r1', 'R-14', [new UnitFixture('u1', 1, 'a')]));

    expect($encoded['unitCount'])->toBe(1);
});

it('crosses only public, non-static, initialized properties', function () {
    expect($this->encoder->encode(new SecretiveFixture()))->toBe(['shown' => 'yes']);
});

it('resolves a Lazy field and encodes what it returns', function () {
    $encoded = $this->encoder->encode(new LazyFixture(Lazy::of(fn () => [new UnitFixture('u1', 1, 'a')])));

    expect($encoded)->toBe(['units' => [['id' => 'u1', 'position' => 1, 'name' => 'a']]]);
});

describe('the boundary refuses everything that is not Data', function () {
    it('refuses a model at the top level', function () {
        $this->encoder->encode(new ModelFixture());
    })->throws(DataBoundaryException::class, 'ModelFixture');

    it('refuses a model hidden inside a list', function () {
        try {
            $this->encoder->encode([new UnitFixture('u1', 1, 'a'), new ModelFixture()]);
            expect()->fail('the boundary let a model through inside a list');
        } catch (DataBoundaryException $e) {
            expect($e->getMessage())->toContain('ModelFixture')->toContain('$[1]');
        }
    });

    it('refuses a model hidden inside a map value', function () {
        try {
            $this->encoder->encode(['rack' => ['owner' => new ModelFixture()]]);
            expect()->fail('the boundary let a model through inside a map');
        } catch (DataBoundaryException $e) {
            expect($e->getMessage())->toContain('$.rack.owner');
        }
    });

    it('refuses a model hidden inside a Lazy field', function () {
        try {
            $this->encoder->encode(new LazyFixture(Lazy::of(fn () => new ModelFixture())));
            expect()->fail('the boundary let a model through inside a Lazy');
        } catch (DataBoundaryException $e) {
            expect($e->getMessage())->toContain('ModelFixture')->toContain('$.units');
        }
    });

    it('refuses a date and says how to fix it', function () {
        try {
            $this->encoder->encode(['at' => new DateTimeImmutable()]);
            expect()->fail('the boundary let a DateTimeImmutable through');
        } catch (DataBoundaryException $e) {
            expect($e->getMessage())->toContain('Format it to a string');
        }
    });

    it('refuses a backed enum and says how to fix it', function () {
        try {
            $this->encoder->encode(['status' => Status::Active]);
            expect()->fail('the boundary let an enum through');
        } catch (DataBoundaryException $e) {
            expect($e->getMessage())->toContain('->value');
        }
    });

    it('refuses a closure', function () {
        $this->encoder->encode(['fn' => fn () => 1]);
    })->throws(DataBoundaryException::class);

    it('refuses a resource', function () {
        $handle = fopen('php://memory', 'r');
        try {
            $this->encoder->encode(['handle' => $handle]);
            expect()->fail('the boundary let a resource through');
        } catch (DataBoundaryException $e) {
            expect($e->getMessage())->toContain('resource');
        } finally {
            fclose($handle);
        }
    });
});

it('refuses a cycle instead of recursing forever', function () {
    // Data objects are readonly, so a cycle cannot be tied through properties.
    // A Lazy field resolving back to its own holder still can, and the guard
    // has to catch it rather than recursing until the stack goes.
    $holder = null;
    $lazy = Lazy::of(static function () use (&$holder): mixed {
        return $holder;
    });
    $holder = new LazyFixture($lazy);

    expect(fn () => $this->encoder->encode($holder))
        ->toThrow(DataBoundaryException::class, 'cycle');
});

it('refuses nesting past the depth limit', function () {
    $deep = 'leaf';
    for ($i = 0; $i <= Encoder::MAX_DEPTH + 1; $i++) {
        $deep = [$deep];
    }

    expect(fn () => $this->encoder->encode($deep))
        ->toThrow(DataBoundaryException::class, 'nesting');
});
