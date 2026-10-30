<?php

declare(strict_types=1);

/**
 * The framework's side of the query builder (ADR 0057): sort and filter
 * fields generated from Data fields, a paginator as a Scroll, cursors bound
 * as route tokens are, and builder reads through the query runner -- its
 * read-only scope, its batching and its cache key.
 */

namespace Tinvar\Tests\Orm\QueryBuilderFramework;

use Tinvar\Attributes\FieldsOf;
use Tinvar\Attributes\From;
use Tinvar\Attributes\MapsFrom;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PanelContext;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Data\Data;
use Tinvar\Data\DataFields;
use Tinvar\Data\Scroll;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\PageCursors;
use Tinvar\Http\Request;
use Tinvar\Orm\InvalidCursor;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Page;
use Tinvar\Orm\SignedCursors;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\FieldOf;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

use function Tinvar\Data\scroll;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/shelves.php';

#[MapsFrom(Shelf::class)]
final readonly class ShelfCardData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public int $position,
        /** Renamed: not the model's name, so no sort field. */
        #[From('note')]
        public ?string $remark,
        /** No column at all. */
        public string $label = '',
    ) {}
}

/** No #[MapsFrom]: nothing says which of its fields are columns. */
final readonly class LooseData extends Data
{
    public function __construct(public string $name) {}
}

#[FieldsOf(ShelfCardData::class)]
enum ShelfSort: string
{
    case Name = 'name';
    case Position = 'position';
}

/** Names the hidden column no card shows. */
#[FieldsOf(ShelfCardData::class)]
enum LeakySort: string
{
    case Name = 'name';
    case Secret = 'secret';
}

enum UnmarkedSort: string
{
    case Name = 'name';
}

final readonly class ShelfListRequest extends FormRequest
{
    public function __construct(
        #[FieldOf(ShelfCardData::class)]
        public string $sort = 'name',
        #[FieldOf(ShelfCardData::class, only: ['position'])]
        public string $filter = 'position',
    ) {}
}

final readonly class ShelfNamesData extends Data
{
    /** @param list<string> $names */
    public function __construct(public array $names, public int $runs) {}
}

#[QueryAttribute(name: 'shelves.names', cache: '5m'), NoAuthorization(reason: 'fixture: a builder read through the runner')]
final class ShelfNames
{
    public static int $runs = 0;

    public function __construct(private readonly Orm $orm) {}

    public function __invoke(): ShelfNamesData
    {
        self::$runs++;

        return new ShelfNamesData(
            array_map(static fn (Shelf $shelf): string => $shelf->name, Shelf::query($this->orm)->orderBy('position')->fetch()),
            self::$runs,
        );
    }
}

#[QueryAttribute(name: 'shelves.items'), NoAuthorization(reason: 'fixture: a builder read through the runner')]
final class ShelfItems
{
    public function __construct(private readonly Orm $orm) {}

    public function __invoke(int $position): ShelfNamesData
    {
        $shelf = Shelf::query($this->orm)->where('position', $position)->first();

        return new ShelfNamesData($shelf === null ? [] : array_map(static fn ($item): string => $item->name, $shelf->items->all()), 0);
    }
}

function user(string $id): Authenticatable
{
    return new class ($id) implements Authenticatable {
        public function __construct(private string $id) {}

        public function authIdentifier(): string
        {
            return $this->id;
        }
    };
}

describe('fields from Data', function () {
    it('lists the fields mapped from a same-named column, and no other', function () {
        expect(DataFields::of(ShelfCardData::class))->toBe(['id', 'name', 'position'])
            ->and(fn () => DataFields::of(LooseData::class))->toThrow(\LogicException::class, '#[MapsFrom]')
            ->and(fn () => DataFields::of(Shelf::class))->toThrow(\LogicException::class, 'not a Data class');
    });

    it('generates an #[In] list from them for a form request', function () {
        $validator = new Validator();

        expect($validator->validate(ShelfListRequest::class, ['sort' => 'position'])->sort)->toBe('position')
            ->and(fn () => $validator->validate(ShelfListRequest::class, ['sort' => 'secret']))->toThrow(ValidationException::class)
            ->and(fn () => $validator->validate(ShelfListRequest::class, ['sort' => 'note']))->toThrow(ValidationException::class)
            ->and(fn () => $validator->validate(ShelfListRequest::class, ['filter' => 'name']))->toThrow(ValidationException::class)
            ->and(fn () => new FieldOf(ShelfCardData::class, only: ['secret']))->toThrow(\LogicException::class, 'secret');
    });

    it('reads an enum case from a request, once the enum is held to its Data', function () {
        $orm = Shelves::sqlite()->orm();
        $sort = DataFields::pick(ShelfSort::class, 'position');

        expect($sort)->toBe(ShelfSort::Position)
            ->and(DataFields::pick(ShelfSort::class, 'secret'))->toBeNull()
            ->and(DataFields::pick(ShelfSort::class, ['position']))->toBeNull()
            ->and($orm->actAs('acme', static fn (): ?string => Shelf::query($orm)->orderBy($sort, 'desc')->first()?->name))->toBe('Foxtrot')
            ->and(fn () => DataFields::pick(LeakySort::class, 'name'))->toThrow(\LogicException::class, "'secret'")
            ->and(fn () => DataFields::pick(UnmarkedSort::class, 'name'))->toThrow(\LogicException::class, '#[FieldsOf]');
    });

    it('fails the build for an enum that names a column no field shows', function () {
        $violations = BuildRules::checkFieldEnums([ShelfSort::class, LeakySort::class, UnmarkedSort::class]);

        expect(array_map(static fn ($v): string => $v->where, $violations))->toBe([LeakySort::class, UnmarkedSort::class])
            ->and($violations[0]->rule)->toBe(BuildRules::FIELD_ENUMS_NAME_DATA_FIELDS);
    });
});

describe('scroll()', function () {
    it('makes a Scroll of a paginator\'s page', function () {
        $page = new Page(['c', 'd'], page: 2, perPage: 2, total: 5);

        $scroll = scroll($page, matchOn: 'id');

        expect([$scroll->items, $scroll->page, $scroll->previous, $scroll->next, $scroll->total, $scroll->matchOn])
            ->toBe([['c', 'd'], 2, 1, 3, 5, 'id'])
            ->and(Scroll::fromPage(new Page([], 9, 2, 5))->previous)->toBe(3);
    });
});

describe('page cursors', function () {
    // The Orm, the auth and panel contexts, a maker of page cursors for the
    // request as it stands, and setters for its session and build.
    $setup = static function (): array {
        $orm = Shelves::sqlite()->orm();
        $auth = new AuthContext();
        $panels = new PanelContext();
        $session = 'session-a';
        $build = 'b1';
        $make = static function () use (&$session, &$build, $auth, $orm, $panels): PageCursors {
            $current = $session;

            return new PageCursors(
                new Signer('test-key', SignedCursors::PURPOSE),
                new BuildRegistry($build),
                static fn (Request $r): string => $current,
                $auth,
                $orm,
                $panels,
            );
        };

        return [$orm, $auth, $panels, $make, static function (string $s) use (&$session): void {
            $session = $s;
        }, static function (string $b) use (&$build): void {
            $build = $b;
        }];
    };

    it('opens a cursor only for the session, user, epoch, tenant, panel and build it was sealed for', function () use ($setup) {
        [$orm, $auth, $panels, $make, $setSession, $setBuild] = $setup();
        $request = new Request('GET', '/');
        $auth->login(user('u1'), '1');
        $query = Shelf::query($orm)->orderBy('position');
        $sealed = $orm->actAs('acme', static fn (): ?string => $query->cursorPaginate(1, null, $make()->for($request))->next);
        $opens = static fn (): bool => (bool) $orm->actAs('acme', static fn (): array => $query->cursorPaginate(1, $sealed, $make()->for($request))->items);

        expect($opens())->toBeTrue();

        $auth->login(user('u1'), '2');
        expect($opens)->toThrow(InvalidCursor::class);
        $auth->login(user('u2'), '1');
        expect($opens)->toThrow(InvalidCursor::class);
        $auth->login(user('u1'), '1');

        expect(fn () => $panels->within('admin', $opens))->toThrow(InvalidCursor::class);

        $setBuild('b2');
        expect($opens)->toThrow(InvalidCursor::class);
        $setBuild('b1');

        $setSession('session-b');
        expect($opens)->toThrow(InvalidCursor::class);
        $setSession('session-a');

        expect(fn () => $orm->actAs('globex', static fn (): array => $query->cursorPaginate(1, $sealed, $make()->for($request))->items))
            ->toThrow(InvalidCursor::class)
            ->and($opens())->toBeTrue();
    });

    it('refuses to make cursors for a request with no session', function () use ($setup) {
        [, , , $make, $setSession] = $setup();
        $setSession('');

        expect(fn () => $make()->for(new Request('GET', '/')))->toThrow(\LogicException::class, 'session');
    });
});

describe('through the query runner', function () {
    it('keys a builder read\'s cache by the tenant and the auth epoch', function () {
        $orm = Shelves::sqlite()->orm();
        $auth = new AuthContext();
        $registry = new QueryRegistry(static fn (string $class): object => new $class($orm));
        $registry->register(ShelfNames::class);
        $runner = new QueryRunner($registry, $orm, new AllowAllGate(), cache: new QueryCache(new ArrayStore(), $auth));
        ShelfNames::$runs = 0;
        $call = [['id' => 0, 'name' => 'shelves.names', 'args' => []]];
        $run = static fn (string $tenant, string $epoch): array => $auth->actingAs(
            user('u1'),
            static fn (): array => $orm->actAs($tenant, static fn (): array => $runner->run($call, allowed: ['shelves.names'])[0]['value']),
            $epoch,
        );

        $acme = $run('acme', '1');
        $again = $run('acme', '1');
        $globex = $run('globex', '1');
        $moved = $run('acme', '2');

        expect($acme['names'])->toBe(['Alpha', 'Bravo', 'Charlie', 'Foxtrot'])
            ->and($again)->toBe($acme)
            ->and($globex['names'])->toBe(['Delta', 'Echo'])
            ->and($moved['runs'])->toBe(3)
            ->and(ShelfNames::$runs)->toBe(3);
    });

    it('fails a builder read with no tenant closed, as the call\'s own error', function () {
        $orm = Shelves::sqlite()->orm();
        $registry = new QueryRegistry(static fn (string $class): object => new $class($orm));
        $registry->register(ShelfNames::class);
        $runner = new QueryRunner($registry, $orm, new AllowAllGate());

        $result = $runner->run([['id' => 0, 'name' => 'shelves.names', 'args' => []]], allowed: ['shelves.names'])[0];

        expect($result)->not->toHaveKey('value');
    });

    it('batches a relation read on builder rows across a tick\'s calls, in the read-only scope', function () {
        $fixture = Shelves::sqlite();
        $connection = $fixture->connection();
        $orm = $fixture->orm(connection: $connection);
        $registry = new QueryRegistry(static fn (string $class): object => new $class($orm));
        $registry->register(ShelfItems::class);
        $runner = new QueryRunner($registry, $orm, new AllowAllGate());

        $results = $orm->actAs('acme', static fn (): array => $runner->run([
            ['id' => 0, 'name' => 'shelves.items', 'args' => ['position' => 1]],
            ['id' => 1, 'name' => 'shelves.items', 'args' => ['position' => 2]],
        ], allowed: ['shelves.items']));

        expect(array_map(static fn (array $r): array => $r['value']['names'], $results))->toBe([['apple', 'apricot'], ['banana']])
            // A shelf for each call, and one statement for both calls' items.
            ->and(\count(array_filter($connection->statementLog(), static fn (string $sql): bool => str_contains($sql, 'FROM "items"'))))->toBe(1);
    });
});
