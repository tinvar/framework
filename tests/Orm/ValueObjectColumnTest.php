<?php

declare(strict_types=1);

/**
 * "Value-object columns map through a cast interface, so JSON comes back
 * typed" (tinvar-spec.md, "Models"), as the spec's model declares one:
 *
 *     #[Column(type: 'jsonb')] public RackLayout $layout;
 *
 * A class that implements Cast is cast by its type; one the application
 * cannot change is named with #[Column(cast:)]. Backed enums map by value.
 * On SQLite, and on Postgres's jsonb where a server is found
 * (tests/Support/Fixtures/databases.php says how).
 */

namespace Tinvar\Tests\Orm\ValueObjectColumn;

use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Attributes\MapsFrom;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\Encoder;
use Tinvar\Orm\Cast;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

/** The spec's value object: owned by the application, so it is its own cast. */
final readonly class RackLayout implements Cast
{
    /** @param list<int> $reserved */
    public function __construct(
        public int $columns,
        public array $reserved = [],
        public float $weight = 0.0,
    ) {}

    public static function columnType(): string
    {
        return 'json';
    }

    public static function fromColumn(mixed $stored): self
    {
        if (!\is_array($stored) || !\is_int($stored['columns'] ?? null) || !\is_array($stored['reserved'] ?? null)) {
            throw new \UnexpectedValueException('Not a rack layout.');
        }

        return new self(
            $stored['columns'],
            array_values(array_map(static fn (mixed $u): int => \is_int($u) ? $u : throw new \UnexpectedValueException('Not a unit.'), $stored['reserved'])),
            (float) ($stored['weight'] ?? 0.0),
        );
    }

    /** @param self $value */
    public static function toColumn(object $value): array
    {
        return ['columns' => $value->columns, 'reserved' => $value->reserved, 'weight' => $value->weight];
    }

    public function reserve(int $unit): self
    {
        return new self($this->columns, [...$this->reserved, $unit], $this->weight);
    }
}

/** Stands for a library's class: immutable, not declared readonly, and not the application's to change. */
class Price
{
    public function __construct(public readonly int $cents) {}
}

/** @implements Cast<Price> */
final class PriceCast implements Cast
{
    public static function columnType(): string
    {
        return 'integer';
    }

    public static function fromColumn(mixed $stored): Price
    {
        return new Price(\is_int($stored) || (\is_string($stored) && ctype_digit($stored)) ? (int) $stored : throw new \UnexpectedValueException('Not cents.'));
    }

    /** @param Price $value */
    public static function toColumn(object $value): int
    {
        return $value->cents;
    }
}

enum Status: string
{
    case Active = 'active';
    case Retired = 'retired';
}

enum Priority: int
{
    case Low = 1;
    case High = 3;
}

#[Table('vo_racks')]
final class Rack extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column(type: 'jsonb')] public RackLayout $layout;
    #[Column] public ?RackLayout $spare = null;
    #[Column(cast: PriceCast::class)] public Price $price;
    #[Column] public Status $status = Status::Active;
    #[Column] public ?Priority $priority = null;
}

#[Table('vo_slots')]
final class Slot extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public int $position;

    #[BelongsTo] public Rack $rack;
}

final class MutableLayout implements Cast
{
    public int $columns = 0;

    public static function columnType(): string
    {
        return 'json';
    }

    public static function fromColumn(mixed $stored): self
    {
        return new self();
    }

    /** @param self $value */
    public static function toColumn(object $value): array
    {
        return [];
    }
}

#[Table('vo_racks')]
final class MutableColumn extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public MutableLayout $layout;
}

/** A mutable subclass of an immutable value class: refused when one is written. */
final class TaggedPrice extends Price
{
    public string $tag = '';
}

/** A cast that answers with something other than the class it is for. */
final class LiarCast implements Cast
{
    public static function columnType(): string
    {
        return 'integer';
    }

    public static function fromColumn(mixed $stored): object
    {
        return new \stdClass();
    }

    public static function toColumn(object $value): int
    {
        return 0;
    }
}

#[Table('vo_racks')]
final class Lied extends Model
{
    #[Id] public private(set) string $id;
    #[Column(name: 'price', cast: LiarCast::class)] public Price $price;
}

enum Pure
{
    case One;
}

#[Table('vo_racks')]
final class PureEnumColumn extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public Pure $pure;
}

#[Table('vo_racks')]
final class NotACast extends Model
{
    #[Id] public private(set) string $id;
    #[Column(cast: \stdClass::class)] public Price $price;
}

#[Table('vo_racks')]
final class CastOnAString extends Model
{
    #[Id] public private(set) string $id;
    #[Column(cast: PriceCast::class)] public string $price;
}

/** Readonly all the way through its own properties, holding what can change (ADR 0122). */
final class Counter
{
    public int $count = 0;
}

final readonly class Wrapped
{
    public function __construct(public Counter $counter) {}
}

/**
 * A value object whose own properties are all readonly, made by the cast
 * given what the test hands it.
 *
 * @implements Cast<Holder>
 */
final readonly class Holder implements Cast
{
    /** @param array<mixed> $items */
    public function __construct(public string $name, public array $items = [], public ?Price $price = null) {}

    public static function columnType(): string
    {
        return 'json';
    }

    public static function fromColumn(mixed $stored): self
    {
        return NextHolder::$next ?? new self('read');
    }

    /** @param self $value */
    public static function toColumn(object $value): array
    {
        return ['name' => $value->name];
    }
}

/** What Holder::fromColumn() answers next: a test's, to see a read refuse it. */
final class NextHolder
{
    public static ?Holder $next = null;
}

/** A readonly property holding a class with a property that is not readonly. */
final readonly class DeepMutable implements Cast
{
    public function __construct(public Wrapped $wrapped) {}

    public static function columnType(): string
    {
        return 'json';
    }

    public static function fromColumn(mixed $stored): self
    {
        return new self(new Wrapped(new Counter()));
    }

    public static function toColumn(object $value): array
    {
        return [];
    }
}

/** A readonly property holding PHP's own mutable DateTime. */
final readonly class Dated implements Cast
{
    public function __construct(public \DateTime $at, public \DateTimeImmutable $fixed) {}

    public static function columnType(): string
    {
        return 'json';
    }

    public static function fromColumn(mixed $stored): self
    {
        return new self(new \DateTime(), new \DateTimeImmutable());
    }

    public static function toColumn(object $value): array
    {
        return [];
    }
}

#[Table('vo_racks')]
final class DeepMutableColumn extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public DeepMutable $layout;
}

#[Table('vo_racks')]
final class DatedColumn extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public Dated $layout;
}

#[Table('vo_racks')]
final class HolderColumn extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public Holder $layout;
}

final readonly class LayoutData extends Data
{
    /** @param list<int> $reserved */
    public function __construct(public int $columns, public array $reserved) {}
}

#[MapsFrom(Rack::class)]
final readonly class RackData extends Data
{
    public function __construct(public string $name, public LayoutData $layout) {}
}

/** A value object where only a Data object may be. */
final readonly class LeakyRackData extends Data
{
    public function __construct(public RackLayout $layout) {}
}

const SCHEMA = [
    'CREATE TABLE vo_racks (id TEXT PRIMARY KEY, name TEXT, layout TEXT, spare TEXT, price INTEGER, status TEXT, priority INTEGER, pure TEXT)',
    'CREATE TABLE vo_slots (id TEXT PRIMARY KEY, position INTEGER NOT NULL, rack_id TEXT NOT NULL)',
];

function sqlite(): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    foreach (SCHEMA as $statement) {
        $pdo->exec($statement);
    }

    return new Orm(new PdoConnection($pdo), new IdentityMap('test'));
}

function rack(): Rack
{
    $rack = new Rack();
    $rack->name = 'R-01';
    $rack->layout = new RackLayout(4, [1, 2], 2.0);
    $rack->price = new Price(1999);
    $rack->priority = Priority::High;

    return $rack;
}

function reread(Orm $orm, Model $model): Rack
{
    $orm->flush();

    return $orm->find(Rack::class, $model->primaryKey()) ?? throw new \LogicException('The rack is gone.');
}

/** @return array<string, mixed> */
function stored(Orm $orm, string $id): array
{
    return $orm->connection()->select('SELECT layout, spare, price, status, priority FROM vo_racks WHERE id = ?', [$id])[0];
}

/**
 * Every statement $work sends that writes.
 *
 * @return list<string>
 */
function writes(Orm $orm, \Closure $work): array
{
    $connection = $orm->connection();
    \assert($connection instanceof PdoConnection);
    $before = \count($connection->statementLog());
    $work();

    return array_values(array_filter(
        \array_slice($connection->statementLog(), $before),
        static fn (string $sql): bool => !str_starts_with($sql, 'SELECT'),
    ));
}

/**
 * The checks every database gets: what a value object, a cast class and an
 * enum store, and that they come back as they went in.
 */
function roundTrips(Orm $orm): void
{
    $rack = $orm->save(rack());
    $back = reread($orm, $rack);

    expect($back)->not->toBe($rack)
        ->and($back->layout)->toBeInstanceOf(RackLayout::class)
        ->and($back->layout->columns)->toBe(4)
        ->and($back->layout->reserved)->toBe([1, 2])
        ->and($back->layout->weight)->toBe(2.0)
        ->and($back->spare)->toBeNull()
        ->and($back->price)->toBeInstanceOf(Price::class)
        ->and($back->price->cents)->toBe(1999)
        ->and($back->status)->toBe(Status::Active)
        ->and($back->priority)->toBe(Priority::High)
        ->and($back->isDirty())->toBeFalse();

    // Read back unchanged, saved again: nothing to write, however the
    // database spelled the JSON it returned.
    expect(writes($orm, fn () => $orm->save($back)))->toBe([]);

    $back->layout = $back->layout->reserve(7);
    $back->status = Status::Retired;

    expect(array_keys($back->changes()))->toBe(['layout', 'status']);

    $orm->save($back);
    $again = reread($orm, $back);

    expect($again->layout->reserved)->toBe([1, 2, 7])
        ->and($again->status)->toBe(Status::Retired)
        ->and($again->isDirty())->toBeFalse();
}

describe('on SQLite', function () {
    it('reads a value object, a cast class and an enum back typed', function () {
        roundTrips(sqlite());
    });

    it('stores JSON for a JSON cast, the scalar for any other, and an enum by value', function () {
        $orm = sqlite();
        $rack = $orm->save(rack());

        expect(stored($orm, $rack->primaryKey()))->toBe([
            'layout' => '{"columns":4,"reserved":[1,2],"weight":2.0}',
            'spare' => null,
            'price' => 1999,
            'status' => 'active',
            'priority' => 3,
        ]);
    });

    it('writes nothing for a value object left alone or replaced by an equal one', function () {
        $orm = sqlite();
        $rack = $orm->save(rack());
        $rack->layout = new RackLayout(4, [1, 2], 2.0);
        $rack->price = new Price(1999);
        $rack->status = Status::Active;

        expect($rack->isDirty())->toBeFalse()
            ->and(writes($orm, fn () => $orm->save($rack)))->toBe([]);
    });

    it('writes only the value object that was replaced', function () {
        $orm = sqlite();
        $rack = $orm->save(rack());
        $rack->spare = new RackLayout(2);

        expect(writes($orm, fn () => $orm->save($rack)))
            ->toBe(['UPDATE "vo_racks" SET "spare" = ? WHERE "id" = ?']);
        expect(reread($orm, $rack)->spare?->columns)->toBe(2);
    });

    it('reads a NULL column, or JSON null under a nullable property, as null', function () {
        $orm = sqlite();
        $rack = $orm->save(rack());
        $orm->connection()->execute("UPDATE vo_racks SET spare = 'null'");

        expect(reread($orm, $rack)->spare)->toBeNull();
    });

    it('maps a value object into a Data class, and keeps it out of the payload itself', function () {
        $rack = sqlite()->save(rack());

        $data = RackData::from($rack);

        expect($data->layout)->toBeInstanceOf(LayoutData::class)
            ->and($data->layout->reserved)->toBe([1, 2])
            ->and(fn () => (new Encoder())->encode(new LeakyRackData($rack->layout)))->toThrow(DataBoundaryException::class);
    });
});

describe('a stored value that does not decode', function () {
    it('fails the read with a mapping exception naming the model and column', function (string $column, string $value, string $message, string $hidden) {
        $orm = sqlite();
        $rack = $orm->save(rack());
        $orm->connection()->execute("UPDATE vo_racks SET {$column} = ?", [$value]);
        $orm->flush();

        try {
            $orm->find(Rack::class, $rack->primaryKey());
            $this->fail('The row was read.');
        } catch (MappingException $e) {
            expect($e->getMessage())->toContain(Rack::class . "::\${$column}: column '{$column}'")
                ->and($e->getMessage())->toContain($message)
                // Named by its type, never quoted: a column may hold what
                // an error page should not show.
                ->and($e->getMessage())->not->toContain($hidden);
        }
    })->with([
        'malformed JSON' => ['layout', '{"columns": 4, "secret"', 'holds malformed JSON, which does not decode into ' . RackLayout::class . ': JsonException', 'secret'],
        'JSON of another shape' => ['layout', '{"secret": "hunter22"}', 'holds array, which does not decode into ' . RackLayout::class . ': UnexpectedValueException', 'hunter22'],
        'JSON null, not nullable' => ['layout', 'null', 'holds null, which does not decode into ' . RackLayout::class . '.', '"null"'],
        'a value the cast refuses' => ['price', 'twelve-ninety', 'holds string, which does not decode into ' . Price::class, 'twelve'],
        'a value no case has' => ['status', 'mothballed', 'holds string, which does not decode into ' . Status::class, 'mothballed'],
        'a value no int case has' => ['priority', '2', 'holds int, which does not decode into ' . Priority::class, "'2'"],
    ]);

    it('refuses what a cast makes when it is not the property\'s class', function () {
        $orm = sqlite();
        $orm->connection()->execute("INSERT INTO vo_racks (id, price) VALUES (?, 5)", [Uuid::v7()]);

        expect(fn () => $orm->all(Lied::class))
            ->toThrow(MappingException::class, "Lied::\$price: column 'price' holds stdClass, which does not decode into " . Price::class);
    });

    it('builds no half model, and leaves a ghost a ghost', function () {
        $orm = sqlite();
        $rack = $orm->save(rack());
        $orm->connection()->execute('INSERT INTO vo_slots VALUES (?, 1, ?)', [$slotId = Uuid::v7(), $rack->primaryKey()]);
        $orm->connection()->execute("UPDATE vo_racks SET layout = '{'");
        $orm->flush();

        $slot = $orm->find(Slot::class, $slotId) ?? throw new \LogicException('no slot');
        $ghost = $slot->rack;

        expect(fn () => $orm->find(Rack::class, $rack->primaryKey()))->toThrow(MappingException::class)
            ->and((new \ReflectionClass(Rack::class))->isUninitializedLazyObject($ghost))->toBeTrue()
            ->and(fn () => $ghost->name)->toThrow(MappingException::class);

        // Mended, the same ghost reads its row.
        $orm->connection()->execute("UPDATE vo_racks SET layout = '{\"columns\":1,\"reserved\":[]}'");

        expect($orm->find(Rack::class, $rack->primaryKey()))->toBe($ghost)
            ->and($ghost->layout->columns)->toBe(1);
    });
});

describe('what a value-object column may be', function () {
    it('refuses a mutable value class when the model compiles', function () {
        expect(fn () => Metadata::for(MutableColumn::class))->toThrow(
            MappingException::class,
            'MutableColumn::$layout holds ' . MutableLayout::class . ', which is mutable: ' . MutableLayout::class . '::$columns is not readonly.',
        );
    });

    it('refuses a mutable subclass of an immutable value class when it is written', function () {
        $orm = sqlite();
        $rack = rack();
        $rack->price = new TaggedPrice(5);

        expect(fn () => $orm->save($rack))->toThrow(MappingException::class, TaggedPrice::class . '::$tag is not readonly');
    });

    it('refuses what it cannot cast', function (string $class, string $message) {
        expect(fn () => Metadata::for($class))->toThrow(MappingException::class, $message);
    })->with([
        'a pure enum' => [PureEnumColumn::class, 'is the pure enum ' . Pure::class . ', which has no value to store'],
        'a cast that is not a Cast' => [NotACast::class, 'names stdClass as its cast, which does not implement ' . Cast::class],
        'a cast on a builtin type' => [CastOnAString::class, 'has a cast, so it must be typed as one class'],
    ]);
});

// ADR 0122: readonly is one level deep in PHP, and immutability is not.
describe('what a value object holds, all the way down', function () {
    it('refuses a readonly property holding a class that is not immutable, when the model compiles', function () {
        expect(fn () => Metadata::for(DeepMutableColumn::class))->toThrow(
            MappingException::class,
            'holds ' . DeepMutable::class . ', which is mutable: ' . DeepMutable::class . '::$wrapped holds ' . Wrapped::class
                . ', and ' . Wrapped::class . '::$counter holds ' . Counter::class . ', and ' . Counter::class . '::$count is not readonly.',
        );
    });

    it('refuses PHP\'s own DateTime, and takes DateTimeImmutable', function () {
        expect(fn () => Metadata::for(DatedColumn::class))->toThrow(
            MappingException::class,
            Dated::class . '::$at holds DateTime, and DateTime, a class of PHP\'s own whose state is out of reflection\'s sight, may change in place.',
        );
    });

    it('looks inside an array and a class that may be extended, on each value written', function (\Closure $make, string $why) {
        $orm = sqlite();
        $model = new HolderColumn();
        $model->layout = $make();

        expect(fn () => $orm->save($model))->toThrow(MappingException::class, $why);
    })->with([
        'a mutable object in an array' => [fn () => new Holder('h', ['when' => new \DateTime()]), 'it holds a DateTime, and DateTime, a class of PHP\'s own'],
        'one nested in arrays' => [fn () => new Holder('h', [[['deep' => new Counter()]]]), 'it holds a ' . Counter::class . ', and ' . Counter::class . '::$count is not readonly'],
        'an element held by reference' => [function () {
            // Kept alive past the call, so the array shares it with
            // something that can still write it.
            static $shared = 1;
            $items = [&$shared];

            return new Holder('h', $items);
        }, 'an array in it holds an element by reference'],
        'a mutable subclass where a class is typed' => [fn () => new Holder('h', [], new TaggedPrice(5)), 'it holds a ' . TaggedPrice::class . ', and ' . TaggedPrice::class . '::$tag is not readonly'],
    ]);

    it('writes and reads one that holds only what cannot change', function () {
        $orm = sqlite();
        $model = new HolderColumn();
        $model->layout = new Holder('h', ['units' => [1, 2], 'at' => new \DateTimeImmutable('2026-01-01'), 'price' => new Price(3)], new Price(5));
        $orm->save($model);

        NextHolder::$next = new Holder('read', [new Price(1)]);
        try {
            $orm->flush();
            expect($orm->find(HolderColumn::class, $model->primaryKey())?->layout->name)->toBe('read');
        } finally {
            NextHolder::$next = null;
        }
    });

    // A readonly property left uninitialized can still be set once, from
    // inside its class: the value changes after it was made (review of
    // ADR 0122).
    it('refuses a value with a property left uninitialized, at the top or nested, read or written', function () {
        $bare = static fn (string $class): object => new \ReflectionClass($class)->newInstanceWithoutConstructor();
        $orm = sqlite();

        $model = new HolderColumn();
        $model->layout = $bare(Holder::class);
        expect(fn () => $orm->save($model))->toThrow(MappingException::class, 'not initialized');

        $model->layout = new Holder('h', [$bare(Price::class)]);
        expect(fn () => $orm->save($model))->toThrow(MappingException::class, 'not initialized');

        $model->layout = new Holder('h');
        $orm->save($model);
        NextHolder::$next = new Holder('read', [], $bare(Price::class));
        try {
            $orm->flush();
            expect(fn () => $orm->find(HolderColumn::class, $model->primaryKey()))->toThrow(MappingException::class, 'not initialized');
        } finally {
            NextHolder::$next = null;
        }
    });

    it('refuses a mutable value a cast makes when the row is read', function () {
        $orm = sqlite();
        $model = new HolderColumn();
        $model->layout = new Holder('h');
        $orm->save($model);

        NextHolder::$next = new Holder('read', [new \DateTime()]);
        try {
            $orm->flush();
            expect(fn () => $orm->find(HolderColumn::class, $model->primaryKey()))->toThrow(MappingException::class, 'it holds a DateTime');
        } finally {
            NextHolder::$next = null;
        }
    });
});

describe('make:migration', function () {
    it('types a value-object column as its cast stores it, and an enum as enum(), held to its values', function () {
        $connection = new PdoConnection(new \PDO('sqlite::memory:'));
        $source = (string) (new MigrationGenerator($connection, new SqliteDialect()))->generate([Rack::class], '2026-10-26');

        expect($source)->toContain("\$table->json('layout');")
            ->toContain("\$table->json('spare')->nullable();")
            ->toContain("\$table->integer('price');")
            ->toContain("\$table->enum('status', \\Tinvar\\Tests\\Orm\\ValueObjectColumn\\Status::class)->default('active');")
            ->toContain("\$table->enum('priority', \\Tinvar\\Tests\\Orm\\ValueObjectColumn\\Priority::class)->nullable();");
    });
});

/**
 * Runs $test against a schema of its own on the Postgres server found, the
 * value-object columns jsonb.
 *
 * @param \Closure(Orm): void $test
 */
function onPostgres(\Closure $test): void
{
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo) use ($test): void {
        $pdo->exec('CREATE TABLE vo_racks (id UUID PRIMARY KEY, name TEXT, layout JSONB, spare JSONB, price INTEGER, status TEXT, priority INTEGER, pure TEXT)');

        $test(new Orm(new PdoConnection($pdo, 'pgsql'), new IdentityMap('test')));
    });
}

describe('on Postgres', function () {
    it('reads a value object back typed from jsonb, and writes nothing for it unchanged', function () {
        onPostgres(static fn (Orm $orm) => roundTrips($orm));
    });

    it('keeps a typed value through jsonb\'s own spelling of it', function () {
        onPostgres(function (Orm $orm): void {
            $rack = $orm->save(rack());

            // jsonb keeps neither the order of the keys nor their spacing.
            expect(stored($orm, $rack->primaryKey())['layout'])->toBe('{"weight": 2.0, "columns": 4, "reserved": [1, 2]}')
                ->and(reread($orm, $rack)->layout->weight)->toBe(2.0);
        });
    });

    it('fails a read of jsonb of another shape with a mapping exception', function () {
        onPostgres(function (Orm $orm): void {
            $rack = $orm->save(rack());
            $orm->connection()->execute('UPDATE vo_racks SET layout = \'{"columns": "four"}\'');
            $orm->flush();

            expect(fn () => $orm->find(Rack::class, $rack->primaryKey()))
                ->toThrow(MappingException::class, "column 'layout' holds array, which does not decode into " . RackLayout::class);
        });
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
