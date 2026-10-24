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
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Data\Encoder;
use Tinvar\Data\PayloadContext;
use Tinvar\Data\Scroll;

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
