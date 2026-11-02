<?php

declare(strict_types=1);

/**
 * Meilisearch as the index (ADR 0154): reached through the outbound client
 * held to its one origin and the application's #[OutboundPolicy]; an index
 * per tenant, chosen from the ORM's scope; written from jobs queued after
 * the commit, in each row's tenant; the key a Secret no error repeats.
 *
 * Meilisearch here is a closure behind FakeOutboundHttp that keeps its
 * indexes in memory and answers as its API does.
 */

namespace Tinvar\Tests\Search\Meilisearch;

use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Auth\AuthContext;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Secret;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\Envelope;
use Tinvar\Jobs\Payload;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Search\MeilisearchSearch;
use Tinvar\Search\Search;
use Tinvar\Search\SearchableModel;
use Tinvar\Search\SearchBackends;
use Tinvar\Search\SearchFailed;
use Tinvar\Search\SearchTerms;
use Tinvar\Search\SqliteSearch;
use Tinvar\Search\SyncSearchDocuments;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Search\Fixtures\Note;
use Tinvar\Tests\Search\Fixtures\Ticket;

use function Tinvar\Tests\Search\Fixtures\createTables;
use function Tinvar\Tests\Search\Fixtures\names;
use function Tinvar\Tests\Search\Fixtures\note;
use function Tinvar\Tests\Search\Fixtures\ticket;

require_once __DIR__ . '/Fixtures/search.php';

const KEY = 'meili-api-key-0123456789abcdef';

/** Meilisearch, in memory: its indexes, and every request it was sent. */
final class FakeMeilisearch
{
    /** @var array<string, array<string, array<string, mixed>>> uid => id => document */
    public array $indexes = [];

    /** @var array<string, array<string, mixed>> uid => settings */
    public array $settings = [];

    public function respond(OutboundRequest $request): OutboundResponse
    {
        if (($request->headers['authorization'] ?? '') !== 'Bearer ' . KEY) {
            return new OutboundResponse(401, [], json_encode(['message' => 'The provided API key is invalid: ' . ($request->headers['authorization'] ?? ''), 'code' => 'invalid_api_key']));
        }
        $path = parse_url($request->url, PHP_URL_PATH);
        $body = $request->body === '' ? null : json_decode($request->body, true);

        if ($request->method === 'GET' && $path === '/indexes') {
            return self::json(['results' => array_map(static fn (string $uid): array => ['uid' => $uid], array_keys($this->indexes))]);
        }
        if (preg_match('#^/indexes/([^/]+)(/.*)?$#', (string) $path, $m) === 1) {
            $uid = rawurldecode($m[1]);
            $rest = $m[2] ?? '';
            if ($request->method === 'DELETE' && $rest === '') {
                if (!isset($this->indexes[$uid])) {
                    return new OutboundResponse(404, [], '{"code":"index_not_found"}');
                }
                unset($this->indexes[$uid]);

                return self::json(['taskUid' => 1], 202);
            }
            if ($request->method === 'PATCH' && $rest === '/settings') {
                $this->settings[$uid] = $body;
                $this->indexes[$uid] ??= [];

                return self::json(['taskUid' => 1], 202);
            }
            if ($request->method === 'POST' && $rest === '/documents') {
                foreach ($body as $document) {
                    $this->indexes[$uid][(string) $document['id']] = $document;
                }

                return self::json(['taskUid' => 1], 202);
            }
            if ($request->method === 'POST' && $rest === '/documents/delete-batch') {
                if (!isset($this->indexes[$uid])) {
                    return new OutboundResponse(404, [], '{"code":"index_not_found"}');
                }
                foreach ($body as $id) {
                    unset($this->indexes[$uid][(string) $id]);
                }

                return self::json(['taskUid' => 1], 202);
            }
            if ($request->method === 'POST' && $rest === '/search') {
                if (!isset($this->indexes[$uid])) {
                    return new OutboundResponse(404, [], '{"code":"index_not_found"}');
                }
                $hits = $this->matching($uid, (string) $body['q']);

                return self::json(['hits' => \array_slice($hits, $body['offset'], $body['limit']), 'estimatedTotalHits' => \count($hits)]);
            }
        }
        if ($request->method === 'POST' && $path === '/multi-search') {
            $hits = [];
            foreach ($body['queries'] as $query) {
                array_push($hits, ...$this->matching($query['indexUid'], $query['q']));
            }

            return self::json(['hits' => \array_slice($hits, $body['federation']['offset'], $body['federation']['limit']), 'estimatedTotalHits' => \count($hits)]);
        }

        return new OutboundResponse(404, [], '{"message":"no route"}');
    }

    /** @return list<array{id: string}> */
    private function matching(string $uid, string $q): array
    {
        $hits = [];
        foreach ($this->indexes[$uid] ?? [] as $id => $document) {
            $text = strtolower(implode(' ', array_map(strval(...), array_diff_key($document, ['id' => true]))));
            $all = true;
            foreach (explode(' ', $q) as $word) {
                $all = $all && str_contains($text, $word);
            }
            if ($all) {
                $hits[] = ['id' => (string) $id];
            }
        }

        return $hits;
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body, int $status = 200): OutboundResponse
    {
        return new OutboundResponse($status, ['content-type' => 'application/json'], json_encode($body));
    }
}

/**
 * A search over Meilisearch, its writes queued on an array queue.
 *
 * @return array{Orm, Search, FakeMeilisearch, ArrayQueue, FakeOutboundHttp, Signer}
 */
function remote(): array
{
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    createTables($connection, 'sqlite');
    $orm = new Orm($connection, new IdentityMap('meili'), Tenancy::flat());
    $meili = new FakeMeilisearch();
    $http = new FakeOutboundHttp($meili->respond(...));
    $queue = new ArrayQueue();
    $signer = new Signer('search-test-key-of-thirty-two-bytes', Envelope::PURPOSE);
    $dispatcher = new Dispatcher($queue, $orm, $signer, new AuthContext());
    $search = new Search($orm, new MeilisearchSearch($http, 'https://search.example.test', new Secret(KEY), 'app_'), [Note::class, Ticket::class], $dispatcher);

    return [$orm, $search->observe(), $meili, $queue, $http, $signer];
}

/** Runs every job queued, as a worker would: each in the tenant it was sealed with. */
function work(Orm $orm, Search $search, ArrayQueue $queue, Signer $signer): void
{
    foreach ($queue->pushed() as $pending) {
        $envelope = Envelope::open($pending->payload, $signer);
        expect($envelope->class)->toBe(SyncSearchDocuments::class);
        $run = static function () use ($envelope, $orm, $search): void {
            $job = Payload::construct($envelope->class, $envelope->arguments, $orm);
            \assert($job instanceof SyncSearchDocuments);
            $job->handle($search);
        };
        $envelope->tenant === '' ? $run() : $orm->actAs($envelope->tenant, $run);
    }
}

it('queues a write after the commit, and indexes the row as it then is when the job runs', function () {
    [$orm, $search, $meili, $queue, , $signer] = remote();

    $orm->transaction(function () use ($orm, $queue): void {
        note($orm, 'Blue mug');
        expect($queue->pushed())->toBe([]);
    });
    expect($queue->pushed())->toHaveCount(1)
        ->and($meili->indexes)->toBe([]);

    work($orm, $search, $queue, $signer);

    expect(array_keys($meili->indexes))->toBe(['app_notes'])
        ->and($meili->settings['app_notes'])->toBe(['searchableAttributes' => ['a', 'b'], 'displayedAttributes' => ['id']])
        ->and(names($search->for(Note::class)->query('blue')->get()))->toBe(['Blue mug']);
});

it('keeps each tenant in an index of its own, named from the ORM\'s scope, with no filter sent', function () {
    [$orm, $search, $meili, $queue, $http, $signer] = remote();
    $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
    $orm->actAs('globex', fn () => ticket($orm, 'Printer on fire'));
    work($orm, $search, $queue, $signer);

    $acme = 'app_tickets_t' . substr(hash('sha256', 'acme'), 0, 32);
    $globex = 'app_tickets_t' . substr(hash('sha256', 'globex'), 0, 32);
    expect(array_keys($meili->indexes))->toEqualCanonicalizing([$acme, $globex])
        ->and($orm->actAs('acme', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer jammed'])
        ->and($orm->actAs('globex', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer on fire']);

    foreach ($http->sent as $request) {
        expect($request->body)->not->toContain('filter')
            ->and($request->url)->toStartWith('https://search.example.test/');
    }
    $searches = array_values(array_filter($http->sent, static fn (OutboundRequest $r): bool => str_ends_with($r->url, '/search')));
    expect(json_decode($searches[0]->body, true))->toBe(['q' => 'printer', 'limit' => 20, 'offset' => 0, 'attributesToRetrieve' => ['id']]);
});

it('takes a deleted row out of its tenant\'s index from a job, and reindexes each tenant', function () {
    [$orm, $search, $meili, $queue, , $signer] = remote();
    $ticket = $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
    $orm->actAs('globex', fn () => ticket($orm, 'Printer on fire'));
    work($orm, $search, $queue, $signer);

    $orm->actAs('acme', fn () => $orm->delete($ticket));
    work($orm, $search, $queue, $signer);
    expect($orm->actAs('acme', fn (): array => $search->for(Ticket::class)->query('printer')->get()))->toBe([]);

    $meili->indexes = [];
    expect($search->reindex(Ticket::class))->toBe(1)
        ->and($orm->actAs('globex', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer on fire']);
});

it('searches what was typed as words in the body, never as Meilisearch syntax', function () {
    [$orm, $search, , $queue, $http, $signer] = remote();
    note($orm, 'Blue mug');
    work($orm, $search, $queue, $signer);

    $search->for(Note::class)->query('"blue" AND tenant_id = acme')->get();
    $last = end($http->sent);

    expect(json_decode($last->body, true)['q'])->toBe('blue and tenant id acme');
});

it('holds every request to the origin configured, and never follows a redirect elsewhere', function () {
    $http = new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(302, ['location' => 'https://evil.example/']));
    $backend = new MeilisearchSearch($http, 'https://search.example.test', new Secret(KEY));

    expect(fn () => $backend->search(SearchableModel::of(Note::class), SearchTerms::parse('mug'), [''], 10, 0))
        ->toThrow(SearchFailed::class, 'answered 302')
        ->and($http->sent[0]->destinations?->hosts)->toBe(['search.example.test'])
        ->and($http->sent[0]->maxRedirects)->toBe(0);
});

it('is refused a private address the outbound policy does not allow, saying how to allow it', function () {
    $backend = new MeilisearchSearch(new FakeOutboundHttp(null, new OutboundPolicy()), 'http://10.0.0.7:7700', new Secret(KEY));

    expect(fn () => $backend->search(SearchableModel::of(Note::class), SearchTerms::parse('mug'), [''], 10, 0))
        ->toThrow(SearchFailed::class, '#[OutboundPolicy]');

    $allowed = new MeilisearchSearch(new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(200, [], '{"hits":[],"estimatedTotalHits":0}'), new OutboundPolicy(allow: ['10.0.0.7'])), 'http://10.0.0.7:7700', new Secret(KEY));
    expect($allowed->search(SearchableModel::of(Note::class), SearchTerms::parse('mug'), [''], 10, 0)->total)->toBe(0);
});

it('never repeats the key in an error, even one Meilisearch quotes it in', function () {
    $meili = new FakeMeilisearch();
    $backend = new MeilisearchSearch(new FakeOutboundHttp($meili->respond(...)), 'https://search.example.test', new Secret('wrong-key-that-meili-quotes-back'));

    try {
        $backend->search(SearchableModel::of(Note::class), SearchTerms::parse('mug'), [''], 10, 0);
        $this->fail('a refused key is a failure');
    } catch (SearchFailed $e) {
        expect($e->getMessage())->toContain('answered 401')
            ->and($e->getMessage())->toContain('TINVAR_MEILISEARCH_KEY')
            ->and($e->getMessage())->not->toContain('wrong-key-that-meili-quotes-back');
    }
    expect(print_r($backend, true))->not->toContain('wrong-key-that-meili-quotes-back');
});

it('refuses a URL that is not an origin, and a prefix it cannot name an index with', function () {
    $http = new FakeOutboundHttp();

    expect(fn () => new MeilisearchSearch($http, 'https://user:pass@search.example.test'))->toThrow(\InvalidArgumentException::class, 'no path, credentials')
        ->and(fn () => new MeilisearchSearch($http, 'https://search.example.test/indexes'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new MeilisearchSearch($http, 'ftp://search.example.test'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new MeilisearchSearch($http, 'https://search.example.test', null, 'bad prefix!'))->toThrow(\InvalidArgumentException::class, 'PREFIX');
});

describe('the backend the environment names', function () {
    it('is the database with nothing set, and Meilisearch where named', function () {
        $connection = new PdoConnection(new \PDO('sqlite::memory:'));
        $backends = new SearchBackends(new FakeOutboundHttp());

        expect($backends->backend($connection, []))->toBeInstanceOf(SqliteSearch::class)
            ->and($backends->backend($connection, ['TINVAR_SEARCH_BACKEND' => 'meilisearch', 'TINVAR_MEILISEARCH_URL' => 'http://localhost:7700']))->toBeInstanceOf(MeilisearchSearch::class);
    });

    it('stops the boot where Meilisearch is named without a URL, or in production without TLS or a key', function () {
        $connection = new PdoConnection(new \PDO('sqlite::memory:'));
        $backends = new SearchBackends(new FakeOutboundHttp());

        expect(fn () => $backends->backend($connection, ['TINVAR_SEARCH_BACKEND' => 'meilisearch']))->toThrow(ConfigException::class, 'TINVAR_MEILISEARCH_URL is required')
            ->and(fn () => $backends->backend($connection, ['TINVAR_SEARCH_BACKEND' => 'meilisearch', 'TINVAR_MEILISEARCH_URL' => 'http://search.internal:7700', 'TINVAR_ENV' => 'production']))
            ->toThrow(ConfigException::class, 'must be https')
            ->and(fn () => $backends->backend($connection, ['TINVAR_SEARCH_BACKEND' => 'meilisearch', 'TINVAR_MEILISEARCH_URL' => 'https://search.internal', 'TINVAR_ENV' => 'production']))
            ->toThrow(ConfigException::class, 'TINVAR_MEILISEARCH_KEY is required')
            ->and($backends->backend($connection, ['TINVAR_SEARCH_BACKEND' => 'meilisearch', 'TINVAR_MEILISEARCH_URL' => 'https://search.internal', 'TINVAR_MEILISEARCH_KEY' => KEY, 'TINVAR_ENV' => 'production']))
            ->toBeInstanceOf(MeilisearchSearch::class);
    });

    it('refuses a remote backend with no Dispatcher to queue its writes', function () {
        $connection = new PdoConnection(new \PDO('sqlite::memory:'));

        expect(fn () => new Search(new Orm($connection), new MeilisearchSearch(new FakeOutboundHttp(), 'https://search.example.test'), [Note::class]))
            ->toThrow(\InvalidArgumentException::class, 'Dispatcher');
    });
});
