<?php

declare(strict_types=1);

/**
 * #[Optional] and #[Always], the encoder's half.
 *
 * ADR 0045. An optional field is Inertia's optional prop -- "never on full
 * renders, only when named in X-Only" -- so its Lazy resolver runs only for
 * a partial reload naming its dot path, and every other response carries
 * the path in its place. An always field decides which segments a partial
 * reload renders, which is read off a loader's own Data; met deeper, the
 * encoder refuses it rather than ignore it.
 */

namespace Tinvar\Tests\Data\OptionalField;

use Tinvar\Attributes\Always;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Optional;
use Tinvar\Attributes\Rescue;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;
use Tinvar\Data\PayloadContext;

/** How often each resolver ran. */
final class Resolved
{
    /** @var array<string, int> */
    public static array $count = [];

    public static function of(string $name, mixed $value): Lazy
    {
        return Lazy::of(static function () use ($name, $value): mixed {
            self::$count[$name] = (self::$count[$name] ?? 0) + 1;

            return $value;
        });
    }
}

final readonly class EventData extends Data
{
    public function __construct(public string $id, public string $text) {}
}

final readonly class OrderData extends Data
{
    /** @param Lazy<list<EventData>> $history */
    public function __construct(
        public string $id,
        #[Optional]
        public Lazy $history,
    ) {}
}

final readonly class OrderPageData extends Data
{
    /**
     * @param Lazy<string> $notes
     * @param list<OrderData> $related
     */
    public function __construct(
        public string $heading,
        #[Optional]
        public Lazy $notes,
        public OrderData $order,
        public array $related,
    ) {}
}

final readonly class OnceOptionalData extends Data
{
    /** @param Lazy<int> $totals */
    public function __construct(
        #[Optional, Once(key: 'orders.totals')]
        public Lazy $totals,
    ) {}
}

final readonly class RescuedOptionalData extends Data
{
    /** @param Lazy<int> $flaky */
    public function __construct(
        #[Optional, Rescue]
        public Lazy $flaky,
    ) {}
}

final readonly class NotLazyData extends Data
{
    public function __construct(#[Optional] public string $text) {}
}

final readonly class BothData extends Data
{
    /** @param Lazy<int> $count */
    public function __construct(#[Optional, Always] public Lazy $count) {}
}

final readonly class BadgeData extends Data
{
    public function __construct(#[Always] public int $unread) {}
}

final readonly class ShellData extends Data
{
    public function __construct(public string $name, public BadgeData $badge) {}
}

function page(): OrderPageData
{
    Resolved::$count = [];

    return new OrderPageData(
        heading: 'Order 7',
        notes: Resolved::of('notes', 'Leave at the door'),
        order: new OrderData('o-7', Resolved::of('history', [new EventData('e-1', 'Placed')])),
        related: [
            new OrderData('o-5', Resolved::of('related0', [])),
            new OrderData('o-6', Resolved::of('related1', [new EventData('e-2', 'Shipped')])),
        ],
    );
}

/** @param list<string> $only @param list<string> $except */
function asked(array $only, array $except = []): PayloadContext
{
    return new PayloadContext(only: $only, except: $except);
}

describe('an optional field', function () {
    it('is sent as the path that asks for it, and its resolver never runs, on a full render', function () {
        $encoded = (new Encoder())->encode(page(), segment: 'page');

        expect($encoded['notes'])->toBe(['$optional' => 'page.notes'])
            ->and($encoded['order']['history'])->toBe(['$optional' => 'page.order.history'])
            ->and($encoded['related'][1]['history'])->toBe(['$optional' => 'page.related.1.history'])
            ->and($encoded['heading'])->toBe('Order 7')
            ->and(Resolved::$count)->toBe([]);
    });

    it('is resolved when a partial reload names it, and the rest stay unasked', function () {
        $encoded = (new Encoder())->encode(page(), asked(['page.notes']), 'page');

        expect($encoded['notes'])->toBe('Leave at the door')
            ->and($encoded['order']['history'])->toBe(['$optional' => 'page.order.history'])
            ->and(Resolved::$count)->toBe(['notes' => 1]);
    });

    it('is resolved inside a Data object, and in one row of a list by its place', function () {
        $encoded = (new Encoder())->encode(page(), asked(['page.order.history', 'page.related.1.history']), 'page');

        expect($encoded['order']['history'])->toBe([['id' => 'e-1', 'text' => 'Placed']])
            ->and($encoded['related'][0]['history'])->toBe(['$optional' => 'page.related.0.history'])
            ->and($encoded['related'][1]['history'])->toBe([['id' => 'e-2', 'text' => 'Shipped']])
            ->and(Resolved::$count)->toBe(['history' => 1, 'related1' => 1]);
    });

    it('is resolved for a path below it', function () {
        $encoded = (new Encoder())->encode(page(), asked(['page.order.history.0']), 'page');

        expect($encoded['order']['history'])->toBe([['id' => 'e-1', 'text' => 'Placed']]);
    });

    // "Only when named": a reload of the segment, or of a field the optional
    // one sits in, costs what it did before the field was added.
    it('is not asked for by naming its segment or a field it sits inside', function () {
        $encoded = (new Encoder())->encode(page(), asked(['page', 'page.order', 'page.related']), 'page');

        expect($encoded['notes'])->toBe(['$optional' => 'page.notes'])
            ->and($encoded['order']['history'])->toBe(['$optional' => 'page.order.history'])
            ->and(Resolved::$count)->toBe([]);
    });

    it('is not resolved when X-Except names it or a field above it, whatever X-Only says', function () {
        $encoded = (new Encoder())->encode(
            page(),
            asked(['page.notes', 'page.order.history'], ['page.notes', 'page.order']),
            'page',
        );

        expect($encoded['notes'])->toBe(['$optional' => 'page.notes'])
            ->and($encoded['order']['history'])->toBe(['$optional' => 'page.order.history'])
            ->and(Resolved::$count)->toBe([]);
    });

    it('is known by its own segment: a layout\'s path never asks for the page\'s field', function () {
        $encoded = (new Encoder())->encode(page(), asked(['shop.notes']), 'page');

        expect($encoded['notes'])->toBe(['$optional' => 'page.notes']);
    });

    // Query results and fragments are encoded with no context, so nothing
    // in them is ever asked for.
    it('is never resolved outside a page render', function () {
        $encoded = (new Encoder())->encode(page());

        expect($encoded['notes'])->toBe(['$optional' => '$.notes'])
            ->and(Resolved::$count)->toBe([]);
    });

    it('is a once field once asked for, and is not held before', function () {
        Resolved::$count = [];
        $data = new OnceOptionalData(Resolved::of('totals', 42));

        $unasked = (new Encoder())->encode($data, new PayloadContext(heldOnce: ['orders.totals']), 'page');
        $asked = (new Encoder())->encode($data, asked(['page.totals']), 'page');
        $held = (new Encoder())->encode($data, new PayloadContext(heldOnce: ['orders.totals'], only: ['page.totals']), 'page');

        expect($unasked['totals'])->toBe(['$optional' => 'page.totals'])
            ->and($asked['totals'])->toBe(['$once' => 'orders.totals', 'ttl' => null, 'tags' => [], 'value' => 42])
            ->and($held['totals'])->toBe(['$once' => 'orders.totals', 'held' => true])
            ->and(Resolved::$count)->toBe(['totals' => 1]);
    });

    it('is rescued once asked for, if its resolver throws', function () {
        $digests = [];
        $encoder = new Encoder(static function (\Throwable $e) use (&$digests): string {
            $digests[] = $e->getMessage();

            return 'abc123';
        });
        $data = new RescuedOptionalData(Lazy::of(static fn (): int => throw new \RuntimeException('down')));

        expect($encoder->encode($data, segment: 'page')['flaky'])->toBe(['$optional' => 'page.flaky'])
            ->and($digests)->toBe([])
            ->and($encoder->encode($data, asked(['page.flaky']), 'page')['flaky'])->toBe(['$rescued' => 'abc123'])
            ->and($digests)->toBe(['down']);
    });

    it('may only be a Lazy field, and never #[Always] too', function () {
        expect(fn () => DataShape::optional(NotLazyData::class))
            ->toThrow(\LogicException::class, 'only a Lazy field can be')
            ->and(fn () => DataShape::optional(BothData::class))
            ->toThrow(\LogicException::class, 'both #[Optional] and #[Always]');
    });
});

describe('an always field', function () {
    it('is read off the class, and sent as any field is', function () {
        expect(DataShape::always(BadgeData::class))->toBe(['unread' => true])
            ->and(DataShape::always(ShellData::class))->toBe([])
            ->and((new Encoder())->encode(new BadgeData(3), segment: 'shop'))->toBe(['unread' => 3]);
    });

    // A segment is chosen before anything is encoded, from the loader's own
    // Data: deeper, the mark would be silently ignored.
    it('is refused below a loader\'s own Data, where it could never take effect', function () {
        expect(fn () => (new Encoder())->encode(new ShellData('Shop', new BadgeData(3)), segment: 'shop'))
            ->toThrow(\LogicException::class, 'is met at $.badge and has an #[Always] field');
    });
});
