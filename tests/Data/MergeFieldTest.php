<?php

declare(strict_types=1);

/**
 * #[Merge] fields and Scroll pages, the encoder's half.
 *
 * ADR 0020. Under RSC the browser merges rendered items, not data: each
 * response renders only its own items, and a client component puts them
 * with those shown. What crosses is the items and what that component needs
 * -- the field's dot path, which it is known by; where the items go; what
 * matches one item to another; and whether `X-Reset` asked for a replace.
 */

namespace Tinvar\Tests\Data\MergeField;

use Tinvar\Attributes\Merge;
use Tinvar\Attributes\Optional;
use Tinvar\Attributes\Rescue;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Data\Encoder;
use Tinvar\Data\PayloadContext;
use Tinvar\Data\Scroll;
use Tinvar\Support\Lazy;

final readonly class MessageData extends Data
{
    public function __construct(public string $id, public string $text) {}
}

final readonly class FeedData extends Data
{
    /**
     * @param list<MessageData> $latest
     * @param list<MessageData> $older
     * @param Scroll<MessageData> $history
     */
    public function __construct(
        #[Merge(matchOn: 'id')]
        public array $latest,
        #[Merge(prepend: true)]
        public array $older,
        public Scroll $history,
    ) {}
}

final readonly class NotAListData extends Data
{
    public function __construct(#[Merge] public string $text) {}
}

final readonly class BadMatchData extends Data
{
    /** @param list<int> $ids */
    public function __construct(#[Merge(matchOn: 'not a name')] public array $ids) {}
}

/** A page of a paginator's shape: its rows under `data`, and what it says of them. */
final readonly class MessagePageData extends Data
{
    /** @param list<MessageData> $data */
    public function __construct(public array $data, public int $total, public ?string $next = null) {}
}

final readonly class InboxData extends Data
{
    /**
     * @param Lazy<list<MessageData>> $more
     * @param Lazy<list<MessageData>> $drafts
     * @param Lazy<MessagePageData>   $archive
     * @param Lazy<list<MessageData>> $flaky
     */
    public function __construct(
        #[Merge(append: 'data', matchOn: 'id')]
        public MessagePageData $page,
        #[Optional, Merge(matchOn: 'id')]
        public Lazy $more,
        #[Merge]
        public Lazy $drafts,
        #[Merge(prepend: 'data')]
        public Lazy $archive,
        #[Merge, Rescue]
        public Lazy $flaky,
    ) {}
}

final readonly class PathIntoAListData extends Data
{
    /** @param list<MessageData> $rows */
    public function __construct(#[Merge(append: 'data')] public array $rows) {}
}

final readonly class PathToNoListData extends Data
{
    public function __construct(#[Merge(append: 'total')] public MessagePageData $page) {}
}

final readonly class LazyNotAListData extends Data
{
    /** @param Lazy<string> $text */
    public function __construct(#[Merge] public Lazy $text) {}
}

function inbox(?\Closure $flaky = null): InboxData
{
    return new InboxData(
        page: new MessagePageData([new MessageData('m-1', 'one')], total: 9, next: 'c2'),
        more: Lazy::of(static fn (): array => [new MessageData('m-3', 'three')]),
        drafts: Lazy::value([new MessageData('d-1', 'draft')]),
        archive: Lazy::value(new MessagePageData([new MessageData('a-1', 'old')], total: 1)),
        flaky: Lazy::of($flaky ?? static fn (): array => []),
    );
}

function feed(): FeedData
{
    return new FeedData(
        latest: [new MessageData('m-2', 'two')],
        older: [new MessageData('m-0', 'zero')],
        history: Scroll::paginate([new MessageData('m-1', 'one')], page: 2, perPage: 1, total: 3, matchOn: 'id'),
    );
}

describe('a merged field', function () {
    it('crosses as its items, known by its dot path, appended and matched as declared', function () {
        $encoded = (new Encoder())->encode(feed(), segment: 'page');

        expect($encoded['latest'])->toBe([
            '$merge' => 'page.latest',
            'mode' => 'append',
            'reset' => false,
            'matchOn' => 'id',
            'items' => [['id' => 'm-2', 'text' => 'two']],
        ]);
    });

    it('is put before what is shown when declared to be', function () {
        $encoded = (new Encoder())->encode(feed(), segment: 'page');

        expect($encoded['older']['mode'])->toBe('prepend')
            ->and($encoded['older']['matchOn'])->toBeNull();
    });

    it('is rooted at the segment it is the data of', function () {
        $encoded = (new Encoder())->encode(feed(), segment: 'inbox');

        expect($encoded['latest']['$merge'])->toBe('inbox.latest')
            ->and($encoded['history']['$scroll'])->toBe('inbox.history');
    });

    // X-Reset names a dot path; only that field is sent to replace.
    it('is marked to replace what is shown when X-Reset names it, and only then', function () {
        $encoded = (new Encoder())->encode(feed(), new PayloadContext(resets: ['page.latest']), 'page');

        expect($encoded['latest']['reset'])->toBeTrue()
            ->and($encoded['older']['reset'])->toBeFalse()
            ->and($encoded['history']['reset'])->toBeFalse();
    });
});

// ADR 0120: Inertia's `append: 'data'`, a path into the field. The object
// crosses as it is, the list under the path merged.
describe('a merge into a path of the field', function () {
    it('sends the object with the list under the path as a merged field, known by its own dot path', function () {
        $encoded = (new Encoder())->encode(inbox(), segment: 'page');

        expect($encoded['page'])->toBe([
            'data' => [
                '$merge' => 'page.page.data',
                'mode' => 'append',
                'reset' => false,
                'matchOn' => 'id',
                'items' => [['id' => 'm-1', 'text' => 'one']],
            ],
            'total' => 9,
            'next' => 'c2',
        ]);
    });

    it('prepends when the path is given as prepend, through a Lazy too', function () {
        $encoded = (new Encoder())->encode(inbox(), segment: 'page');

        expect($encoded['archive']['data']['mode'])->toBe('prepend')
            ->and($encoded['archive']['data']['$merge'])->toBe('page.archive.data')
            ->and($encoded['archive']['total'])->toBe(1);
    });

    // Inertia's reset names the prop; naming the list itself does as well.
    it('is reset when X-Reset names the field, or the list under it', function (string $named) {
        $encoded = (new Encoder())->encode(inbox(), new PayloadContext(resets: [$named]), 'page');

        expect($encoded['page']['data']['reset'])->toBeTrue();
    })->with(['page.page', 'page.page.data']);

    it('refuses a path into a field that is not a Data object, or to a property that is not a list', function () {
        expect(fn () => DataShape::merged(PathIntoAListData::class))->toThrow(\LogicException::class, "into 'data'")
            ->and(fn () => DataShape::merged(PathToNoListData::class))->toThrow(\LogicException::class, "into 'total'");
    });

    it('refuses a path that is not a property name, and a field merged both ways', function () {
        expect(fn () => new Merge(append: 'data.rows'))->toThrow(\InvalidArgumentException::class, 'not a property name')
            ->and(fn () => new Merge(prepend: 'data', append: 'data'))->toThrow(\InvalidArgumentException::class, 'not both')
            ->and(fn () => new Merge(prepend: true, append: 'data'))->toThrow(\InvalidArgumentException::class, 'not both');
    });
});

describe('a merged Lazy', function () {
    it('is merged as the list it resolves to', function () {
        $encoded = (new Encoder())->encode(inbox(), segment: 'page');

        expect($encoded['drafts'])->toBe([
            '$merge' => 'page.drafts',
            'mode' => 'append',
            'reset' => false,
            'matchOn' => null,
            'items' => [['id' => 'd-1', 'text' => 'draft']],
        ]);
    });

    // An #[Optional] field may be merged: not resolved until a partial
    // reload names it, and then merged into what is shown -- "load more"
    // that costs nothing on any other render.
    it('is sent as its path, unresolved, while optional and not asked for', function () {
        $resolved = false;
        $data = new InboxData(
            page: new MessagePageData([], 0),
            more: Lazy::of(static function () use (&$resolved): array {
                $resolved = true;

                return [];
            }),
            drafts: Lazy::value([]),
            archive: Lazy::value(new MessagePageData([], 0)),
            flaky: Lazy::value([]),
        );

        $encoded = (new Encoder())->encode($data, segment: 'page');

        expect($encoded['more'])->toBe(['$optional' => 'page.more'])
            ->and($resolved)->toBeFalse();
    });

    it('is merged once a partial reload names it', function () {
        $encoded = (new Encoder())->encode(inbox(), new PayloadContext(only: ['page.more']), 'page');

        expect($encoded['more']['$merge'])->toBe('page.more')
            ->and($encoded['more']['matchOn'])->toBe('id')
            ->and($encoded['more']['items'])->toBe([['id' => 'm-3', 'text' => 'three']]);
    });

    it('sends a rescued failure as its marker, unmerged', function () {
        $encoder = new Encoder(static fn (\Throwable $e, ?string $ref): string => 'ref-1');

        $encoded = $encoder->encode(inbox(static fn (): array => throw new \RuntimeException('down')), segment: 'page');

        expect($encoded['flaky'])->not->toHaveKey('$merge')
            ->and($encoded['flaky'])->toHaveKey('$rescued');
    });

    it('refuses a value that is not a list once resolved', function () {
        expect(fn () => (new Encoder())->encode(new LazyNotAListData(Lazy::value('x'))))
            ->toThrow(\LogicException::class, 'is not a list');
    });
});

describe('a Scroll page', function () {
    it('crosses as its items, which page they are and the pages either side', function () {
        $encoded = (new Encoder())->encode(feed(), new PayloadContext(resets: ['page.history']), 'page');

        expect($encoded['history'])->toBe([
            '$scroll' => 'page.history',
            'reset' => true,
            'page' => 2,
            'previous' => 1,
            'next' => 3,
            'total' => 3,
            'perPage' => 1,
            'pageName' => 'page',
            'matchOn' => 'id',
            'items' => [['id' => 'm-1', 'text' => 'one']],
        ]);
    });

    it('knows the pages either side of it from the count', function (int $page, ?int $previous, ?int $next) {
        $scroll = Scroll::paginate([], page: $page, perPage: 10, total: 25);

        expect([$scroll->previous, $scroll->next])->toBe([$previous, $next]);
    })->with([
        'the first' => [1, null, 2],
        'one between' => [2, 1, 3],
        'the last, part full' => [3, 2, null],
        'past the end, back to the last' => [9, 3, null],
    ]);

    it('reads a page number from a query string, and anything else as the first page', function (mixed $value, int $page) {
        expect(Scroll::pageFrom($value))->toBe($page);
    })->with([
        ['3', 3],
        ['0', 1],
        ['-2', 1],
        ['2.5', 1],
        ['99999999999999999999', 1],
        [null, 1],
        [['2'], 1],
    ]);

    it('counts its pages from one and names its parameter plainly', function () {
        expect(fn () => new Scroll([], page: 0, previous: null, next: null))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new Scroll([], page: 1, previous: null, next: null, pageName: 'a b'))
            ->toThrow(\InvalidArgumentException::class);
    });
});

describe('what cannot be merged', function () {
    it('refuses a mark on anything but a list', function () {
        expect(fn () => DataShape::merged(NotAListData::class))->toThrow(\LogicException::class, 'only a list');
    });

    it('refuses a matchOn that is not a property name', function () {
        expect(fn () => (new Encoder())->encode(new BadMatchData([1])))->toThrow(\LogicException::class, 'not a name');
    });
});
