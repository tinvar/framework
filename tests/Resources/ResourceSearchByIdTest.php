<?php

declare(strict_types=1);

/**
 * A list's search is LIKE over its searchable() columns (Resource::search()),
 * and the ORM refuses LIKE on a key -- a UUID, which Postgres matches with no
 * LIKE -- on every database. A searchable id was accepted at boot, and every
 * search of that list was then a 500; it is refused when the resource is
 * read, as every other declaration a list could not serve is.
 */

namespace Tinvar\Tests\Resources\SearchById;

use Tinvar\Attributes\AdminResource;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Resources\Column;
use Tinvar\Resources\Resource;
use Tinvar\Resources\ResourceDefinition;
use Tinvar\Tests\Resources\Fixtures\Ticket;

require_once __DIR__ . '/Fixtures/app.php';

#[AdminResource(model: Ticket::class, panel: 'staff')]
final class TicketsById extends Resource
{
    public static function columns(): array
    {
        return [Column::make('title'), Column::make('id')->searchable()];
    }
}

it('cannot search a key by LIKE, which is what a list\'s search does', function () {
    $orm = new Orm(new PdoConnection(new \PDO('sqlite::memory:'), 'test'), new IdentityMap('test'));

    expect(static fn () => $orm->query(Ticket::class)->where(static fn ($q) => $q->orWhere('id', 'like', '%abc%')))
        ->toThrow(\InvalidArgumentException::class);
});

it('refuses a searchable id when the resource is read, rather than a 500 at every search', function () {
    expect(static fn () => ResourceDefinition::of(TicketsById::class))->toThrow(\LogicException::class, 'searchable');
});
