<?php

declare(strict_types=1);

/**
 * A #[Once] field on a Data class met more than once in a payload: the rows
 * of a list.
 *
 * ADR 0020. One key per class gave every row the same key, so the browser
 * kept one row's content and put it back in every row's place. A class whose
 * key names no subject of its own is refused the second time it is met; one
 * that names its subject from its own properties -- `row.badge:{id}` -- gets
 * a key per row.
 */

namespace Tinvar\Tests\Data\OnceRows;

use Tinvar\Attributes\Once;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;
use Tinvar\Data\PayloadContext;

final readonly class RowData extends Data
{
    /** @param Lazy<string> $badge */
    public function __construct(
        public string $id,
        #[Once]
        public Lazy $badge,
    ) {}
}

final readonly class NamedRowData extends Data
{
    /** @param Lazy<string> $badge */
    public function __construct(
        public string $id,
        #[Once(key: 'row.badge')]
        public Lazy $badge,
    ) {}
}

final readonly class IdentifiedRowData extends Data
{
    /** @param Lazy<string> $badge */
    public function __construct(
        public string $id,
        #[Once(key: 'row.badge:{id}')]
        public Lazy $badge,
    ) {}
}

final readonly class ListData extends Data
{
    /** @param list<Data> $rows */
    public function __construct(public array $rows) {}
}

final readonly class NullableIdentityData extends Data
{
    public function __construct(
        public ?string $id,
        #[Once(key: 'row:{id}')]
        public int $n,
    ) {}
}

final readonly class MissingIdentityData extends Data
{
    public function __construct(#[Once(key: 'row:{nope}')] public int $n) {}
}

final readonly class LongKeyData extends Data
{
    public function __construct(
        public string $id,
        #[Once(key: 'k{id}{id}{id}{id}{id}{id}')]
        public int $n,
    ) {}
}

/** @param class-string<RowData|NamedRowData|IdentifiedRowData> $class */
function rows(string $class, string ...$ids): ListData
{
    return new ListData(array_map(
        static fn (string $id): Data => new $class($id, Lazy::of(static fn (): string => "badge {$id}")),
        $ids,
    ));
}

describe('a class whose once key names no subject', function () {
    it('is refused as the rows of a list, by default key', function () {
        expect(fn () => (new Encoder())->encode(rows(RowData::class, 'a', 'b'), segment: 'page'))
            ->toThrow(\LogicException::class, 'met twice in one payload: at $.rows[0] and at $.rows[1]');
    });

    it('is refused as the rows of a list, by a key it names', function () {
        expect(fn () => (new Encoder())->encode(rows(NamedRowData::class, 'a', 'b'), segment: 'page'))
            ->toThrow(\LogicException::class, "under the key 'row.badge', which names no subject of its own");
    });

    it('is refused while it is held, too', function () {
        $held = PayloadContext::none()->withHeldOnce(['row.badge']);

        expect(fn () => (new Encoder())->encode(rows(NamedRowData::class, 'a', 'b'), $held, 'page'))
            ->toThrow(\LogicException::class, 'met twice');
    });

    it('is taken once', function () {
        $encoded = (new Encoder())->encode(rows(RowData::class, 'a'), segment: 'page');

        expect($encoded['rows'][0]['badge']['value'])->toBe('badge a');
    });

    // Each encode() is its own payload: a query's result, a segment.
    it('is taken once in each payload', function () {
        $encoder = new Encoder();
        $encoder->encode(rows(RowData::class, 'a'), segment: 'page');

        expect($encoder->encode(rows(RowData::class, 'b'), segment: 'page')['rows'][0]['badge']['value'])->toBe('badge b');
    });
});

describe('a class whose once key names its subject', function () {
    it('keeps each row under its own key', function () {
        $encoded = (new Encoder())->encode(rows(IdentifiedRowData::class, 'a', 'b'), segment: 'page');

        $a = $encoded['rows'][0]['badge'];
        $b = $encoded['rows'][1]['badge'];
        expect($a['value'])->toBe('badge a')
            ->and($b['value'])->toBe('badge b')
            ->and($a['$once'])->not->toBe($b['$once'])
            ->and($a['$once'])->toMatch(PayloadContext::KEY)
            ->and($a['$once'])->toStartWith('row.badge:');
    });

    it('holds only the rows whose keys are listed', function () {
        $first = (new Encoder())->encode(rows(IdentifiedRowData::class, 'a', 'b'), segment: 'page');
        $held = PayloadContext::none()->withHeldOnce([$first['rows'][0]['badge']['$once']]);

        $again = (new Encoder())->encode(rows(IdentifiedRowData::class, 'a', 'b'), $held, 'page');

        expect($again['rows'][0]['badge'])->toBe(['$once' => $first['rows'][0]['badge']['$once'], 'held' => true])
            ->and($again['rows'][1]['badge']['value'])->toBe('badge b');
    });

    it('is the same key for the same row on any page', function () {
        $one = (new Encoder())->encode(rows(IdentifiedRowData::class, 'a'), new PayloadContext(route: 'one'), 'page');
        $two = (new Encoder())->encode(rows(IdentifiedRowData::class, 'a'), new PayloadContext(route: 'two'), 'page');

        expect($one['rows'][0]['badge']['$once'])->toBe($two['rows'][0]['badge']['$once']);
    });
});

describe('a key that cannot name its subject', function () {
    it('is refused for a property that may be null', function () {
        expect(fn () => DataShape::once(NullableIdentityData::class))->toThrow(\LogicException::class, "by 'id'");
    });

    it('is refused for a property the class does not have', function () {
        expect(fn () => DataShape::once(MissingIdentityData::class))->toThrow(\LogicException::class, "by 'nope'");
    });

    // Each subject is sent as a digest, so a key is as long as those make it.
    it('is refused when its subjects would make it too long for a header', function () {
        expect(fn () => DataShape::once(LongKeyData::class))->toThrow(\LogicException::class, 'cannot carry');
    });
});
