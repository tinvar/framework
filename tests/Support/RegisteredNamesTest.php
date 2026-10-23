<?php

declare(strict_types=1);

/**
 * Views, route names, query names and argument keys, and action names all
 * reach generated TypeScript. They were registered whatever they held, so a
 * quote or a newline in an attribute was on its way into source.
 */

namespace Tinvar\Tests\Support\RegisteredNames;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Query\BatchQuery;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Support\Str;

#[Page('/quoted', view: "racks/show'); alert(1); //")]
final class QuotedView {}

#[Page('/traversal', view: '../secrets/show')]
final class TraversingView {}

#[Page('/empty-segment', view: 'racks//show')]
final class EmptySegmentView {}

#[Layout('/backslash', view: 'racks\\layout')]
final class BackslashLayoutView {}

#[Page('/good', view: 'admin-panel/rack_list', name: 'admin.rackList')]
final class GoodPage {}

#[Page('/quoted-name', view: 'quoted/name', name: "racks.show'")]
final class QuotedRouteName {}

#[Page('/dashed-name', view: 'dashed/name', name: 'racks-show')]
final class DashedRouteName {}

#[Page('/empty-name', view: 'empty/name', name: 'racks..show')]
final class EmptySegmentRouteName {}

#[Query(name: "racks.load'); //"), NoAuthorization(reason: 'naming is what is under test')]
final class QuotedQueryName
{
    public function __invoke(): int
    {
        return 1;
    }
}

#[Query(name: '1racks.load'), NoAuthorization(reason: 'naming is what is under test')]
final class DigitQueryName
{
    public function __invoke(): int
    {
        return 1;
    }
}

#[Query(name: 'racks.batch', args: ['unit-id' => 'string']), NoAuthorization(reason: 'naming is what is under test')]
final class DashedArgument implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        return array_map(static fn (): int => 1, $calls);
    }
}

#[Query(name: 'racks.goodBatch', args: ['unit_id' => 'string']), NoAuthorization(reason: 'naming is what is under test')]
final class GoodBatch implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        return array_map(static fn (): int => 1, $calls);
    }
}

final class BadlyNamedActions
{
    #[Action(name: "racks.move\nX-Injected: 1"), NoAuthorization(reason: 'naming is what is under test')]
    public function move(): ActionResult
    {
        return ActionResult::ok();
    }
}

final class WellNamedActions
{
    #[Action(name: 'racks.move'), NoAuthorization(reason: 'naming is what is under test')]
    public function move(): ActionResult
    {
        return ActionResult::ok();
    }
}

it('refuses a view that is not a slash-separated path', function (string $class) {
    expect(fn () => (new RouteRegistry())->register($class))
        ->toThrow(RoutingException::class, 'which is not a view path');
})->with([QuotedView::class, TraversingView::class, EmptySegmentView::class, BackslashLayoutView::class]);

it('refuses a route name that is not a dotted identifier', function (string $class) {
    expect(fn () => (new RouteRegistry())->register($class))
        ->toThrow(RoutingException::class, 'which is not a dotted identifier');
})->with([QuotedRouteName::class, DashedRouteName::class, EmptySegmentRouteName::class]);

it('refuses a query name that is not a dotted identifier', function (string $class) {
    expect(fn () => (new QueryRegistry())->register($class))
        ->toThrow(\InvalidArgumentException::class, 'which is not a dotted identifier');
})->with([QuotedQueryName::class, DigitQueryName::class]);

it('refuses a query argument key that is not an identifier', function () {
    expect(fn () => (new QueryRegistry())->register(DashedArgument::class))
        ->toThrow(\InvalidArgumentException::class, "key 'unit-id', which is not an identifier");
});

it('refuses an action name that is not a dotted identifier', function () {
    expect(fn () => (new ActionRegistry())->register(BadlyNamedActions::class))
        ->toThrow(ActionException::class, 'which is not a dotted identifier');
});

it('registers names that are well formed', function () {
    expect((new RouteRegistry())->register(GoodPage::class)->name)->toBe('admin.rackList')
        ->and((new QueryRegistry())->register(GoodBatch::class))->toBe('racks.goodBatch')
        ->and((new ActionRegistry())->register(WellNamedActions::class)[0]->name)->toBe('racks.move');
});

it('tells identifiers, dotted identifiers and view paths apart', function () {
    expect(Str::isIdentifier('unit_id'))->toBeTrue()
        ->and(Str::isIdentifier('unit.id'))->toBeFalse()
        ->and(Str::isIdentifier("unit\n"))->toBeFalse()
        ->and(Str::isDottedIdentifier('racks.unitLoad'))->toBeTrue()
        ->and(Str::isDottedIdentifier('racks.'))->toBeFalse()
        ->and(Str::isViewPath('racks/show'))->toBeTrue()
        ->and(Str::isViewPath('/racks/show'))->toBeFalse()
        ->and(Str::isViewPath("racks/show\n"))->toBeFalse();
});
