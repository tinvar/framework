<?php

declare(strict_types=1);

/**
 * AssertablePage on its own: Inertia's has/where/missing with dot paths, the
 * strict scopes, the field names held to the Data class, and Lazy fields --
 * over a value encoded as the renderer is handed it.
 */

namespace Tinvar\Tests\Testing\AssertablePage;

use PHPUnit\Framework\AssertionFailedError;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Optional;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;
use Tinvar\Data\PayloadContext;
use Tinvar\Testing\AssertablePage;

final readonly class UnitData extends Data
{
    public function __construct(public string $name, public int $position) {}
}

final readonly class RackData extends Data
{
    public function __construct(public string $id, public string $name, public ?string $note = null) {}
}

final readonly class RackPageData extends Data
{
    /** @param list<UnitData> $units */
    public function __construct(
        public RackData $rack,
        public array $units,
        public Lazy $history,
        #[Once(key: 'rack.summary')]
        public Lazy $summary,
    ) {}
}

function page(): AssertablePage
{
    $data = new RackPageData(
        new RackData('r-14', 'R-14'),
        [new UnitData('sw-core-01', 1), new UnitData('sw-core-02', 2), new UnitData('pdu-a', 42)],
        Lazy::of(static fn (): array => ['moved', 'racked']),
        Lazy::of(static fn (): UnitData => new UnitData('summary', 3)),
    );

    return new AssertablePage(new Encoder()->encode($data), $data, 'page');
}

it('asserts values and counts by dot path, and scopes into a value or its first item', function () {
    page()
        ->where('rack.name', 'R-14')
        ->where('rack.note', null)
        ->where('units.2.position', fn (int $position): bool => $position === 42)
        ->whereNot('rack.id', 'r-15')
        ->has('units', 3)
        ->has('units', 3, fn (AssertablePage $unit) => $unit->where('name', 'sw-core-01')->where('position', 1))
        ->has('rack', fn (AssertablePage $rack) => $rack->where('id', 'r-14')->etc())
        ->missing('rack.passwordHash')
        ->missing('owner');

    expect(true)->toBeTrue();
});

it('fails a value that differs, a count that differs, and a field that is there when it should be missing', function () {
    expect(fn () => page()->where('rack.name', 'R-15'))->toThrow(AssertionFailedError::class, "'rack.name' in page is not what was expected")
        ->and(fn () => page()->where('rack.name', fn (string $name): bool => $name === 'R-15'))->toThrow(AssertionFailedError::class, 'marked invalid')
        ->and(fn () => page()->has('units', 2))->toThrow(AssertionFailedError::class, 'does not hold 2')
        ->and(fn () => page()->missing('rack.name'))->toThrow(AssertionFailedError::class, 'should not be');
});

// "Assertions are typed by the page's Data class, so a renamed field fails."
it('fails a path naming a field its Data class does not have, at any depth', function () {
    expect(fn () => page()->where('rack.title', 'R-14'))
        ->toThrow(AssertionFailedError::class, RackData::class . " has no field 'title'")
        ->and(fn () => page()->has('units.0.label'))
        ->toThrow(AssertionFailedError::class, UnitData::class . " has no field 'label'")
        ->and(fn () => page()->has('cabinet'))
        ->toThrow(AssertionFailedError::class, RackPageData::class . " has no field 'cabinet'");
});

it('holds a scope opened with a closure to every field in it, unless it says etc()', function () {
    expect(fn () => page()->has('rack', fn (AssertablePage $rack) => $rack->where('name', 'R-14')))
        ->toThrow(AssertionFailedError::class, 'page.rack has fields the test did not assert: id, note');

    page()->has('rack', fn (AssertablePage $rack) => $rack->where('name', 'R-14')->etc());
    expect(true)->toBeTrue();
});

it('tells a Lazy field from a plain one, and scopes into what it resolved to', function () {
    page()
        ->deferred('history')
        ->loadDeferred('history', fn (AssertablePage $history) => $history->where('0', 'moved')->has('1'))
        // A once field is sent inside its wrapper: the value under `value`,
        // still checked against the Data class it resolved to.
        ->deferred('summary')
        ->where('summary.value.name', 'summary');

    expect(fn () => page()->deferred('rack'))->toThrow(AssertionFailedError::class, "'rack' in page is not a Lazy field")
        ->and(fn () => page()->where('summary.value.label', 'x'))->toThrow(AssertionFailedError::class, "has no field 'label'");
});

it('hands the Data object to a typed closure, and fails when it returns false', function () {
    page()->data(fn (RackPageData $page): bool => $page->rack->name === 'R-14' && \count($page->units) === 3);

    expect(fn () => page()->data(fn (RackPageData $page): bool => false))->toThrow(AssertionFailedError::class, 'marked invalid');
});

final readonly class TicketData extends Data
{
    /** @param Lazy<list<string>> $history */
    public function __construct(public string $subject, #[Optional] public Lazy $history) {}
}

// ADR 0045: an #[Optional] field nobody asked for is the path that asks.
it('tells an optional field nobody asked for from one that was sent', function () {
    $data = new TicketData('Printer', Lazy::of(static fn (): array => ['opened']));
    $unasked = new AssertablePage(new Encoder()->encode($data, segment: 'page'), $data, 'page');
    $asked = new AssertablePage(new Encoder()->encode($data, new PayloadContext(only: ['page.history']), 'page'), $data, 'page');

    $unasked->unrequested('history');
    $asked->where('history', ['opened']);

    expect(fn () => $asked->unrequested('history'))->toThrow(AssertionFailedError::class, "'history' in page was sent")
        ->and(fn () => $unasked->unrequested('subject'))->toThrow(AssertionFailedError::class, "'subject' in page was sent");
});

it('refuses a follow-up request from a scope that has no page behind it', function () {
    expect(fn () => page()->reloadOnly('units', fn () => null))->toThrow(\LogicException::class, 'not of a scope inside one')
        ->and(fn () => page()->hasFlash('message'))->toThrow(\LogicException::class);
});
