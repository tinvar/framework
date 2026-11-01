<?php

declare(strict_types=1);

/**
 * What an admin resource declares is held to its model when it registers
 * (ADR 0150): a typo is a refusal at boot naming the class and what is
 * wrong, never a 500 on the first request to its list.
 */

namespace Tinvar\Tests\Resources\Registration;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\AdminResource;
use Tinvar\Attributes\Page;
use Tinvar\Discovery\Classifier;
use Tinvar\Resources\BulkAction;
use Tinvar\Resources\Column;
use Tinvar\Resources\Filter;
use Tinvar\Resources\Resource;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Resources\Fixtures\Ticket;
use Tinvar\Tests\Resources\Fixtures\TicketResource;

require_once __DIR__ . '/Fixtures/app.php';

#[AdminResource(model: Ticket::class, panel: 'staff'), Page('/tickets', view: 'tickets/index')]
final class AlsoAPage extends Resource
{
    public static function columns(): array
    {
        return [Column::make('title')];
    }
}

#[AdminResource(model: \stdClass::class, panel: 'staff')]
final class OfNoModel extends Resource {}

#[AdminResource(model: Ticket::class, panel: 'staff')]
final class NoResource {}

final class NoAttribute extends Resource
{
    public static function columns(): array
    {
        return [Column::make('title')];
    }
}

#[AdminResource(model: Ticket::class, panel: 'staff')]
final class UnknownColumn extends Resource
{
    public static function columns(): array
    {
        return [Column::make('titel')];
    }
}

#[AdminResource(model: Ticket::class, panel: 'staff')]
final class SearchesANumber extends Resource
{
    public static function columns(): array
    {
        return [Column::make('points')->searchable()];
    }
}

#[AdminResource(model: Ticket::class, panel: 'staff')]
final class NoColumns extends Resource {}

#[AdminResource(model: Ticket::class, panel: 'staff')]
final class FiltersOnNothing extends Resource
{
    public static function columns(): array
    {
        return [Column::make('title')];
    }

    public static function filters(): array
    {
        return [Filter::make('title')];
    }
}

#[AdminResource(model: Ticket::class, panel: 'staff')]
final class MissingBulkMethod extends Resource
{
    public static function columns(): array
    {
        return [Column::make('title')];
    }

    public static function bulkActions(): array
    {
        return [BulkAction::make('archive', 'Archive', ability: 'update', method: 'archive')];
    }
}

/** @param class-string $class */
function actionRefusal(string $class): string
{
    try {
        new ActionRegistry()->register($class);
    } catch (ActionException $e) {
        return $e->getMessage();
    }

    throw new \LogicException("{$class} registered.");
}

it('is found by discovery as routes and actions', function () {
    expect(Classifier::classify(new \ReflectionClass(TicketResource::class))[0])->toBe(['routes', 'actions']);
});

it('refuses a resource that declares a page of its own', function () {
    expect(fn () => new RouteRegistry()->register(AlsoAPage::class))->toThrow(RoutingException::class, 'declares a route of its own');
});

it('refuses a resource of something that is not a model', function () {
    expect(fn () => new RouteRegistry()->registerAll([OfNoModel::class]))->toThrow(RoutingException::class, 'which is not a model');
});

it('refuses an #[AdminResource] that is no Resource', function () {
    expect(fn () => new RouteRegistry()->registerAll([NoResource::class]))->toThrow(RoutingException::class, 'with no index() loader');
});

it('refuses a Resource with no #[AdminResource]', function () {
    expect(actionRefusal(NoAttribute::class))->toContain('carries no #[AdminResource]');
});

it('refuses a column the model does not have, naming it', function () {
    expect(actionRefusal(UnknownColumn::class))->toContain("UnknownColumn's column 'titel' names 'titel', which " . Ticket::class . ' does not have');
});

it('refuses a search of a column that holds no text', function () {
    expect(actionRefusal(SearchesANumber::class))->toContain('the search looks in text');
});

it('refuses a resource with no columns', function () {
    expect(actionRefusal(NoColumns::class))->toContain('with no columns');
});

it('refuses a filter that offers nothing', function () {
    expect(actionRefusal(FiltersOnNothing::class))->toContain('offers nothing');
});

it('refuses a bulk action whose method is not there', function () {
    expect(actionRefusal(MissingBulkMethod::class))->toContain('runs archive(), which MissingBulkMethod does not have');
});

it('names its routes, path and views from its model by default', function () {
    $attribute = new AdminResource(model: Ticket::class, panel: 'staff');

    expect([$attribute->slug(), $attribute->basePath(), $attribute->viewDirectory(), $attribute->singular(), $attribute->pluralLabel()])
        ->toBe(['tickets', '/tickets', 'staff/resources', 'Ticket', 'Tickets'])
        ->and(new AdminResource(model: 'App\\OrderLine', panel: 'admin', path: '/lines/')->basePath())->toBe('/lines')
        ->and(new AdminResource(model: 'App\\Category', panel: 'admin')->slug())->toBe('categories')
        ->and(fn () => new AdminResource(model: Ticket::class, panel: 'staff', perPage: 1000))->toThrow(\InvalidArgumentException::class);
});
