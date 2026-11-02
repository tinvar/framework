<?php

declare(strict_types=1);

/**
 * `tinvar search:reindex` (ADR 0152): the models a person chooses, or
 * every one with nobody there, rebuilt under a progress bar.
 */

namespace Tinvar\Tests\Search\Reindex;

use Tinvar\Auth\AuthContext;
use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\Cancelled;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\Envelope;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Search\MeilisearchSearch;
use Tinvar\Search\ReindexCommand;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Search\Search;
use Tinvar\Search\SqliteSearch;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Search\Fixtures\Note;
use Tinvar\Tests\Search\Fixtures\Ticket;

use function Tinvar\Tests\Search\Fixtures\createTables;
use function Tinvar\Tests\Search\Fixtures\names;
use function Tinvar\Tests\Search\Fixtures\sqliteSearch;

require_once __DIR__ . '/Fixtures/search.php';

/**
 * Runs the command, its lines kept.
 *
 * @param list<string> $arguments
 * @return array{int, list<string>, list<string>}
 */
function reindex(Search $search, array $arguments): array
{
    $out = [];
    $err = [];
    $options = [];
    foreach ($arguments as $argument) {
        [$name, $value] = [...explode('=', substr($argument, 2), 2), true];
        $options[$name] = $value;
    }
    $status = new ReindexCommand($search)->run(
        \is_string($options['model'] ?? null) ? $options['model'] : null,
        \is_string($options['tenant'] ?? null) ? $options['tenant'] : null,
        isset($options['force']),
        static function (string $line) use (&$out): void {
            $out[] = $line;
        },
        static function (string $line) use (&$err): void {
            $err[] = $line;
        },
    );

    return [$status, $out, $err];
}

/** @return array{Orm, Search} a search whose rows were written behind its back, so none is indexed */
function unindexed(): array
{
    [$orm, $search, $connection] = sqliteSearch();
    $connection->execute("INSERT INTO notes (id, title, body) VALUES ('018f0000-0000-7000-8000-000000000001', 'Lamp manual', '')");
    $connection->execute("INSERT INTO tickets VALUES ('018f0000-0000-7000-8000-000000000002', 'Lamp broken', '', 'acme')");
    $connection->execute("INSERT INTO tickets VALUES ('018f0000-0000-7000-8000-000000000003', 'Lamp missing', '', 'globex')");

    return [$orm, $search];
}

afterEach(fn () => Prompts::restore());

it('rebuilds every model with nobody there to ask, each tenant as itself', function () {
    [$orm, $search] = unindexed();

    [$status, $out] = reindex($search, []);

    expect($status)->toBe(0)
        ->and($out)->toBe(['indexed 1 Note row', 'indexed 2 Ticket rows'])
        ->and(names($search->for(Note::class)->query('lamp')->get()))->toBe(['Lamp manual'])
        ->and($orm->actAs('globex', fn (): array => names($search->for(Ticket::class)->query('lamp')->get())))->toBe(['Lamp missing']);
});

it('rebuilds the models a person chooses, under a progress bar', function () {
    [, $search] = unindexed();
    $prompts = Prompts::fake(['models' => [Note::class]]);

    [$status, $out] = reindex($search, []);

    expect($status)->toBe(0)
        ->and($out)->toBe(['indexed 1 Note row']);
    $prompts->assertAsked('Which models?')->assertOutputContains('Reindexing');
});

it('rebuilds those --model names, in the tenant --tenant names, and refuses a name it does not know', function () {
    [$orm, $search] = unindexed();

    [$status, $out] = reindex($search, ['--model=Ticket', '--tenant=acme']);
    expect($status)->toBe(0)
        ->and($out)->toBe(['indexed 1 Ticket row'])
        ->and($orm->actAs('acme', fn (): array => names($search->for(Ticket::class)->query('lamp')->get())))->toBe(['Lamp broken'])
        ->and($orm->actAs('globex', fn (): array => $search->for(Ticket::class)->query('lamp')->get()))->toBe([])
        ->and(fn () => reindex($search, ['--model=Order']))->toThrow(\InvalidArgumentException::class, 'names no #[Searchable] model');
});

it('asks before emptying a remote index, and does nothing at no', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    createTables($connection, 'sqlite');
    $orm = new Orm($connection, new IdentityMap('remote'), Tenancy::flat());
    $http = new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(202, [], '{}'));
    $dispatcher = new Dispatcher(new ArrayQueue(), $orm, new Signer('reindex-test-key-of-thirty-two-bytes', Envelope::PURPOSE), new AuthContext());
    $search = new Search($orm, new MeilisearchSearch($http, 'https://search.example.test'), [Note::class], $dispatcher);

    Prompts::fake(['reindex' => false]);
    expect(fn () => reindex($search, []))->toThrow(Cancelled::class, 'Nothing reindexed.')
        ->and($http->sent)->toBe([]);

    Prompts::fake();
    [$status] = reindex($search, ['--force']);
    expect($status)->toBe(0);
});

it('says so where no model is searchable', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $search = new Search(new Orm($connection), new SqliteSearch($connection), []);

    [$status, , $err] = reindex($search, []);

    expect($status)->toBe(1)
        ->and($err[0])->toContain('No model is #[Searchable]');
});
