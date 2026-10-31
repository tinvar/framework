<?php

declare(strict_types=1);

/**
 * A backed enum and a moment on the Data boundary. Only Data crosses
 * (tinvar-spec.md, "Renderer hardening"), so neither object ever reaches
 * the renderer: a Data field declared as a backed enum crosses as its
 * value, one declared as an immutable moment as ISO 8601 in UTC -- the
 * field's declared type is what lets it, and nothing else does. The same
 * object anywhere else, in a list, a map, a mixed field or at the top, is
 * refused as it always was. And the Mapper fills a string field from a
 * model's moment, or a string or int field from its enum, with the same
 * scalar.
 */

namespace Tinvar\Tests\Data\EnumAndMomentFields;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;

enum Stage: string
{
    case Placed = 'placed';
    case Shipped = 'shipped';
}

enum Urgency: int
{
    case Low = 1;
    case High = 2;
}

final readonly class ParcelData extends Data
{
    public function __construct(
        public Stage $stage,
        public ?Urgency $urgency,
        public CarbonImmutable $placedAt,
        public ?CarbonImmutable $shippedAt,
        public \DateTimeImmutable $checkedAt,
    ) {}
}

/** Fields whose types vouch for nothing: what they hold is refused as before. */
final readonly class LooseData extends Data
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public mixed $anything = null,
        public array $extra = [],
        public ?object $thing = null,
        public Stage|string|null $either = null,
        public ?Lazy $later = null,
    ) {}
}

/** A model's shape, as the Mapper reads one: an enum and moments, typed. */
final class ParcelRow
{
    public function __construct(
        public Stage $stage,
        public Urgency $urgency,
        public CarbonImmutable $placedAt,
        public ?CarbonImmutable $shippedAt = null,
    ) {}
}

/** The scalars a view wants, filled from the row's objects. */
final readonly class ParcelSummaryData extends Data
{
    /** @param value-of<Stage> $stage */
    public function __construct(
        public string $stage,
        public int $urgency,
        public string $placedAt,
        public ?string $shippedAt,
    ) {}
}

/** The same objects, kept as the row has them. */
final readonly class ParcelTypedData extends Data
{
    public function __construct(
        public Stage $stage,
        public Urgency $urgency,
        public CarbonImmutable $placedAt,
        public ?CarbonImmutable $shippedAt,
    ) {}
}

function encode(mixed $value): mixed
{
    return new Encoder()->encode($value);
}

describe('a field declared as an enum or a moment', function () {
    it('crosses as the enum\'s value, and the moment as ISO 8601 in UTC, to the millisecond', function () {
        $data = new ParcelData(
            Stage::Shipped,
            Urgency::High,
            new CarbonImmutable('2026-10-31 08:15:30.123456', 'America/New_York'),
            null,
            new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('Asia/Tokyo')),
        );

        expect(encode($data))->toBe([
            'stage' => 'shipped',
            'urgency' => 2,
            'placedAt' => '2026-10-31T12:15:30.123Z',
            'shippedAt' => null,
            'checkedAt' => '2026-10-31T03:00:00.000Z',
        ]);
    });

    it('crosses inside a list of Data, each item by its own declared types', function () {
        $at = new CarbonImmutable('2026-01-01', 'UTC');

        expect(encode(['parcels' => [new ParcelData(Stage::Placed, null, $at, $at, $at)]]))
            ->toBe(['parcels' => [['stage' => 'placed', 'urgency' => null, 'placedAt' => '2026-01-01T00:00:00.000Z', 'shippedAt' => '2026-01-01T00:00:00.000Z', 'checkedAt' => '2026-01-01T00:00:00.000Z']]]);
    });
});

describe('the same objects anywhere else', function () {
    it('refuses them where nothing declares them', function (mixed $value, string $path) {
        expect(fn () => encode($value))->toThrow(DataBoundaryException::class, $path);
    })->with([
        'a Carbon at the top' => [new CarbonImmutable(), 'Refused to encode Carbon\CarbonImmutable at $.'],
        'a Carbon in a list' => [[new CarbonImmutable()], 'Refused to encode Carbon\CarbonImmutable at $[0].'],
        'a Carbon in a map' => [['at' => new CarbonImmutable()], 'Refused to encode Carbon\CarbonImmutable at $.at.'],
        'an enum in a list' => [[Stage::Placed], 'Refused to encode Tinvar\Tests\Data\EnumAndMomentFields\Stage at $[0].'],
        'a Carbon in a mixed field' => [new LooseData(anything: new CarbonImmutable()), 'at $.anything.'],
        'a Carbon in an array field' => [new LooseData(extra: ['at' => new CarbonImmutable()]), 'at $.extra.at.'],
        'a Carbon in an object field' => [new LooseData(thing: new CarbonImmutable()), 'at $.thing.'],
        'an enum in a union field' => [new LooseData(either: Stage::Placed), 'at $.either.'],
        'a mutable Carbon in a mixed field' => [new LooseData(anything: new Carbon()), 'Refused to encode Carbon\Carbon at $.anything.'],
        'a Carbon a Lazy resolves to' => [new LooseData(later: Lazy::value(new CarbonImmutable())), 'at $.later.'],
    ]);

    it('says, of a moment refused, to declare it on a Data field', function () {
        expect(fn () => encode(['at' => new CarbonImmutable()]))->toThrow(DataBoundaryException::class, 'Format it to a string inside the Data class');
    });
});

describe('mapping from a model\'s objects', function () {
    it('fills a string or int field with the enum\'s value, and a string field with the moment in ISO 8601', function () {
        $row = new ParcelRow(Stage::Shipped, Urgency::Low, new CarbonImmutable('2026-10-31 12:00:00.5', 'Europe/Paris'));

        $data = ParcelSummaryData::from($row);

        expect([$data->stage, $data->urgency, $data->placedAt, $data->shippedAt])->toBe(['shipped', 1, '2026-10-31T11:00:00.500Z', null]);
    });

    it('keeps the objects where the Data field declares them, and the encoder sends their scalars', function () {
        $row = new ParcelRow(Stage::Placed, Urgency::High, new CarbonImmutable('2026-10-31', 'UTC'));

        $data = ParcelTypedData::from($row);

        expect($data->stage)->toBe(Stage::Placed)
            ->and($data->placedAt)->toBe($row->placedAt)
            ->and(encode($data))->toBe(['stage' => 'placed', 'urgency' => 2, 'placedAt' => '2026-10-31T00:00:00.000Z', 'shippedAt' => null]);
    });
});
