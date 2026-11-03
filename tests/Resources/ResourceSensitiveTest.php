<?php

declare(strict_types=1);

/**
 * A property marked #[Sensitive] is one the audit log never records (ADR
 * 0156). A resource naming one -- a column, a field, a filter, the label of
 * a record it belongs to -- would draw it on every list, fill it into every
 * edit form or offer its every value as a filter: each is refused when the
 * resource is read, naming the class and the property.
 */

namespace Tinvar\Tests\Resources\Sensitive;

use Tinvar\Attributes\AdminResource;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column as OrmColumn;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Sensitive;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Model;
use Tinvar\Resources\Column;
use Tinvar\Resources\Field;
use Tinvar\Resources\Filter;
use Tinvar\Resources\Resource;
use Tinvar\Resources\ResourceDefinition;

#[Table('clients')]
final class Client extends Model
{
    #[Id] public private(set) string $id;
    #[OrmColumn] public string $name;
    #[OrmColumn, Sensitive] public string $taxNumber;
}

#[Table('invoices')]
final class Invoice extends Model
{
    #[Id] public private(set) string $id;
    #[OrmColumn] public string $number;
    #[BelongsTo] public Client $client;
}

#[AdminResource(model: Client::class, panel: 'staff')]
final class ClientsShowingTax extends Resource
{
    public static function columns(): array
    {
        return [Column::make('name'), Column::make('taxNumber')];
    }
}

#[AdminResource(model: Client::class, panel: 'staff')]
final class ClientsEditingTax extends Resource
{
    public static function columns(): array
    {
        return [Column::make('name')];
    }

    public static function fields(): array
    {
        return [Field::make('name'), Field::make('taxNumber')];
    }
}

#[AdminResource(model: Client::class, panel: 'staff')]
final class ClientsFilteredByTax extends Resource
{
    public static function columns(): array
    {
        return [Column::make('name')];
    }

    public static function filters(): array
    {
        return [Filter::make('taxNumber')->distinct()];
    }
}

#[AdminResource(model: Invoice::class, panel: 'staff')]
final class InvoicesLabelledByTax extends Resource
{
    public static function columns(): array
    {
        return [Column::make('number'), Column::make('client')->labelledBy('taxNumber')];
    }
}

#[AdminResource(model: Client::class, panel: 'staff')]
final class Clients extends Resource
{
    public static function columns(): array
    {
        return [Column::make('name')];
    }

    public static function fields(): array
    {
        return [Field::make('name')];
    }
}

it('refuses a #[Sensitive] property as a column, a field, a filter or a label, naming it', function (string $class, string $where) {
    expect(static fn () => ResourceDefinition::of($class))
        ->toThrow(\LogicException::class, "{$where} names " . Client::class . '::$taxNumber, which is #[Sensitive]');
})->with([
    'column' => [ClientsShowingTax::class, "ClientsShowingTax's column 'taxNumber'"],
    'field' => [ClientsEditingTax::class, "ClientsEditingTax's field 'taxNumber'"],
    'filter' => [ClientsFilteredByTax::class, "ClientsFilteredByTax's filter 'taxNumber'"],
    'label' => [InvoicesLabelledByTax::class, "InvoicesLabelledByTax's column 'client''s label"],
]);

it('reads a resource of the same model that names none', function () {
    expect(ResourceDefinition::of(Clients::class)->fields)->toHaveCount(1);
});
