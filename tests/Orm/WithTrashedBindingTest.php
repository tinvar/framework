<?php

declare(strict_types=1);

/**
 * A trashed row of a #[SoftDeletes] model binds nowhere -- a route
 * loader's model, an endpoint's path model, an action's own model -- as a
 * row gone binds nowhere: a 404. A parameter declared #[WithTrashed] binds
 * one, so a restore action has the row to restore (ADR 0191). Unique
 * counts a trashed row's value as taken, as a plain unique index does,
 * unless told the key is a partial one; and the audit log keeps `trashed`
 * and `restored` for the ORM's own entries.
 */

namespace Tinvar\Tests\Orm\WithTrashedBinding;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Post;
use Tinvar\Audit\AuditLog;
use Tinvar\Auth\Gate;
use Tinvar\Data\Data;
use Tinvar\Endpoints\EndpointException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Endpoints\EndpointRunner;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\SoftDeletes;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\WithTrashed;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Routing\Binder;
use Tinvar\Routing\RouteChain;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Validation\Rules\Unique;
use Tinvar\Validation\ValidationContext;
use Tinvar\Validation\Validator;

#[Table('notes'), SoftDeletes]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $title;
    #[Column] public ?CarbonImmutable $deletedAt = null;
}

final class AllowAll implements Gate
{
    public function allows(Authorize $check, array $arguments): bool
    {
        return true;
    }
}

final readonly class NoteData extends Data
{
    public function __construct(public string $title) {}
}

#[Page('/notes/{note}', view: 'notes/show'), Authorize('view', 'note')]
final class ShowNote
{
    public function __invoke(Note $note): NoteData
    {
        return new NoteData($note->title);
    }
}

#[Page('/bin/{note}', view: 'bin/show'), Authorize('view', 'note')]
final class ShowTrashedNote
{
    public function __invoke(#[WithTrashed] Note $note): NoteData
    {
        return new NoteData($note->title);
    }
}

#[Layout('/bin', view: 'bin/layout'), Authorize('view', 'note')]
final class BinLayout
{
    public function __invoke(?Note $note = null): NoteData
    {
        return new NoteData('');
    }
}

final class Held
{
    public static ?Orm $orm = null;
}

final class NoteActions
{
    #[Action, Authorize('edit', 'note')]
    public function rename(Note $note): ActionResult
    {
        return ActionResult::ok($note->title);
    }

    #[Action, Authorize('restore', 'note')]
    public function restore(#[WithTrashed] Note $note): ActionResult
    {
        Held::$orm?->restore($note);

        return ActionResult::ok($note->title);
    }
}

final class NoteEndpoints
{
    #[Get('/api/notes/{note}', name: 'notes.show'), Authorize('view', 'note')]
    public function show(Note $note): void {}

    #[Post('/api/notes/{note}/restore', name: 'notes.restore'), Authorize('restore', 'note')]
    public function restore(#[WithTrashed] Note $note): void
    {
        Held::$orm?->restore($note);
    }
}

final class MisplacedEndpoint
{
    #[Post('/api/notes', name: 'notes.misplaced'), Authorize('make', Note::class)]
    public function make(#[WithTrashed] string $title = ''): void {}
}

final class MisplacedAction
{
    #[Action, Authorize('make', Note::class)]
    public function make(#[WithTrashed] string $title): ActionResult
    {
        return ActionResult::ok($title);
    }
}

/** @return array{Orm, string, string} the Orm, a live note's id, and a trashed one's */
function notes(): array
{
    $connection = new PdoConnection(new \PDO('sqlite::memory:'), 'sqlite');
    $connection->execute('CREATE TABLE notes (id TEXT PRIMARY KEY, title TEXT NOT NULL, deleted_at TEXT)');
    $orm = new Orm($connection, new IdentityMap('test'));
    $live = new Note();
    $live->title = 'live';
    $gone = new Note();
    $gone->title = 'gone';
    $orm->save($live);
    $orm->save($gone);
    $orm->delete($gone);
    Held::$orm = $orm;

    return [$orm, $live->id, $gone->id];
}

it('answers a route loader with a 404 for a trashed row, unless the parameter takes one', function () {
    [$orm, $live, $gone] = notes();
    $routes = new RouteRegistry();
    $show = $routes->register(ShowNote::class);
    $bin = $routes->register(ShowTrashedNote::class);
    $layout = $routes->register(BinLayout::class);

    expect((new Binder($orm))->resolveChain(new RouteChain($show, [], ['note' => $live]), ['note' => $live])['note']->id)->toBe($live)
        ->and(fn () => (new Binder($orm))->resolveChain(new RouteChain($show, [], ['note' => $gone]), ['note' => $gone]))
        ->toThrow(NotFoundException::class)
        ->and((new Binder($orm))->resolveChain(new RouteChain($bin, [], ['note' => $gone]), ['note' => $gone])['note']->id)->toBe($gone)
        // Shared down the chain: a layout that does not take a trashed row
        // is not handed the page's.
        ->and(fn () => (new Binder($orm))->resolveChain(new RouteChain($bin, [$layout], ['note' => $gone]), ['note' => $gone]))
        ->toThrow(NotFoundException::class);
});

it('answers an action with a 404 for a trashed row, unless the parameter takes one', function () {
    [$orm, $live, $gone] = notes();
    $registry = new ActionRegistry();
    $registry->register(NoteActions::class);
    $runner = new ActionRunner($registry, $orm, new AllowAll(), new Validator($orm));

    expect($runner->run('NoteActions.rename', ['note' => $live])->value)->toBe('live')
        ->and(fn () => $runner->run('NoteActions.rename', ['note' => $gone]))->toThrow(NotFoundException::class)
        ->and($runner->run('NoteActions.restore', ['note' => $gone])->value)->toBe('gone')
        ->and($orm->find(Note::class, $gone))->not->toBeNull();
});

it('answers an endpoint with a 404 for a trashed row, unless the parameter takes one', function () {
    [$orm, $live, $gone] = notes();
    $registry = new EndpointRegistry();
    $registry->register(NoteEndpoints::class);
    $runner = new EndpointRunner(static fn (string $c): object => new $c(), $orm, new AllowAll(), new Validator($orm));

    expect($runner->run($registry->get('notes.show'), ['note' => $live], [], Request::get("/api/notes/{$live}"))->status)->toBeLessThan(300)
        ->and(fn () => $runner->run($registry->get('notes.show'), ['note' => $gone], [], Request::get("/api/notes/{$gone}")))
        ->toThrow(NotFoundException::class)
        ->and($runner->run($registry->get('notes.restore'), ['note' => $gone], [], Request::post("/api/notes/{$gone}/restore"))->status)->toBeLessThan(300)
        ->and($orm->find(Note::class, $gone))->not->toBeNull();
});

it('refuses #[WithTrashed] on a parameter that binds no model', function () {
    expect(fn () => (new EndpointRegistry())->register(MisplacedEndpoint::class))->toThrow(EndpointException::class, 'WithTrashed')
        ->and(fn () => (new ActionRegistry())->register(MisplacedAction::class))->toThrow(ActionException::class, 'WithTrashed');
});

it("counts a trashed row's value as taken, unless told the key is the live rows' alone", function () {
    [$orm] = notes();
    $context = new ValidationContext([], $orm);

    expect((new Unique(Note::class, 'title'))->check('gone', 'title', $context))->not->toBeNull()
        ->and((new Unique(Note::class, 'title', ignoreTrashed: true))->check('gone', 'title', $context))->toBeNull()
        ->and((new Unique(Note::class, 'title', ignoreTrashed: true))->check('live', 'title', $context))->not->toBeNull();
});

it('keeps the events the ORM files a trashed and a restored row under', function () {
    expect(AuditLog::RESERVED)->toContain('trashed')->toContain('restored');
});
