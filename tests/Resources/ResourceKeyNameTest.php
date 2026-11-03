<?php

declare(strict_types=1);

/**
 * A model's key is whichever property carries #[Id], not one named `id`.
 * The list ordered by `id` -- newest first by default, and as the tie-break
 * under any other order -- so a resource over a model whose key is named
 * otherwise was a 500 at every load of its list.
 */

namespace Tinvar\Tests\Resources\KeyName;

use Tinvar\Attributes\AdminResource;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Http\Request;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Resources\Column as ListColumn;
use Tinvar\Resources\Resource;

#[Table('labels')]
final class Label extends Model
{
    #[Id] public private(set) string $code;
    #[Column] public string $name;
}

#[AdminResource(model: Label::class, panel: 'staff')]
final class LabelResource extends Resource
{
    public static function columns(): array
    {
        return [ListColumn::make('name')->sortable()];
    }
}

function labels(): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE labels (code TEXT PRIMARY KEY, name TEXT NOT NULL)');
    $orm = new Orm(new PdoConnection($pdo, 'test'), new IdentityMap('test'));
    foreach (['Blue', 'Amber', 'Amber'] as $name) {
        $label = new Label();
        $label->name = $name;
        $orm->save($label);
    }

    return $orm;
}

it('lists a model whose key is not named id, by its key', function () {
    $list = new LabelResource(labels(), new AllowAllGate())->index(new Request('GET', '/labels', [], []));
    $keys = array_map(static fn ($row): string => $row->id, $list->rows);
    $newestFirst = $keys;
    rsort($newestFirst);

    // Made in one millisecond, keys need not follow the order they were
    // made in: the list holds to their order, newest first by default.
    expect($keys)->toHaveCount(3)->toBe($newestFirst);
});

it('breaks a tie under another order by the key, whatever it is named', function () {
    $list = new LabelResource(labels(), new AllowAllGate())->index(new Request('GET', '/labels', [], ['sort' => 'name']));

    expect(array_map(static fn ($row): ?string => $row->cells['name'], $list->rows))->toBe(['Amber', 'Amber', 'Blue']);
});
