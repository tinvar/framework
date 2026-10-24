<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\PreviousUrlTracking;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Http\FlashStore;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\PreviousUrl;
use Tinvar\Http\PreviousUrlStore;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;

/**
 * "Previous URL: track it for client visits (Inertia's Laravel adapter
 * v3.4.0+), excluding prefetches and partial requests." -- tinvar-spec.md,
 * "Security". Recorded in the application's session store, read back by
 * whatever asks: a loader building a back link, an action sending its caller
 * back, a form posted without script that has no Referer to go back to.
 */
const SESSION_COOKIE = '__Host-test_session';

/** One URL per session, as an application's store keeps it; and a count of what it was asked. */
final class SessionUrls implements PreviousUrlStore
{
    /** @var array<string, string> */
    public array $held = [];

    public int $reads = 0;

    /** @var list<string> */
    public array $writes = [];

    public ?\Throwable $failOnPut = null;

    public function get(Request $request): ?string
    {
        $this->reads++;

        return $this->held[$request->cookie(SESSION_COOKIE) ?? ''] ?? null;
    }

    public function put(Request $request, string $url): void
    {
        if ($this->failOnPut !== null) {
            throw $this->failOnPut;
        }
        $this->writes[] = $url;
        $this->held[$request->cookie(SESSION_COOKIE) ?? ''] = $url;
    }
}

/** What the loaders were told, in order. */
final class Seen
{
    /** @var list<string|null> */
    public static array $previous = [];
}

final readonly class NoteData extends Data
{
    public function __construct(public string $title) {}
}

#[Page('/notes', view: 'notes/index', name: 'notes.index')]
final class NotesPage
{
    public function __invoke(Request $request): NoteData
    {
        Seen::$previous[] = $request->previousUrl();

        return new NoteData('Notes');
    }
}

#[Page('/notes/drafts', view: 'notes/drafts', name: 'notes.drafts')]
final class DraftsPage
{
    public function __invoke(): NoteData
    {
        return new NoteData('Drafts');
    }
}

final class SendElsewhere implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return Response::redirect('/notes');
    }
}

#[Page('/notes/old', view: 'notes/old', name: 'notes.old'), UseMiddleware(SendElsewhere::class)]
final class MovedPage
{
    public function __invoke(): NoteData
    {
        return new NoteData('Moved');
    }
}

final class NoteActions
{
    #[Action(name: 'notes.back'), PublicAccess, NoAuthorization(reason: 'a fixture with no model input')]
    public function back(Request $request): ActionResult
    {
        return ActionResult::ok(['back' => $request->previousUrl()]);
    }

    #[Action(name: 'notes.save'), PublicAccess, NoAuthorization(reason: 'a fixture with no model input')]
    public function save(): ActionResult
    {
        return ActionResult::ok(null)->flash(['saved' => true]);
    }
}

/** Keeps a flash, so a form posted without script is sent back somewhere. */
final class Flashes implements FlashStore
{
    /** @var array<string, array<string, mixed>> */
    public array $held = [];

    public function put(Request $request, array $flash): void
    {
        $this->held[$request->cookie(SESSION_COOKIE) ?? ''] = $flash;
    }

    public function pull(Request $request): array
    {
        return [];
    }
}

/** @param list<\Throwable> $reported */
function kernel(?PreviousUrlStore $urls, array &$reported = []): Kernel
{
    $routes = new RouteRegistry();
    $routes->registerAll([NotesPage::class, DraftsPage::class, MovedPage::class]);

    $actions = new ActionRegistry();
    $actions->register(NoteActions::class);

    return new Kernel(
        orm: new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        log: static function (\Throwable $e) use (&$reported): void {
            $reported[] = $e;
        },
        flashes: new Flashes(),
        previousUrls: $urls,
    );
}

/** A page, recording what its responder was told the previous URL is. */
function page(?string &$told = null): \Closure
{
    return static function (LoadedRoute $loaded, Request $request) use (&$told): Response {
        $told = $request->previousUrl();

        return Response::make(200, '<html></html>', ['content-type' => 'text/html; charset=utf-8']);
    };
}

/**
 * @param array<string, string> $headers
 */
function visit(string $path, array $headers = [], string $session = 'session-a', ?string $query = null): Request
{
    return new Request('GET', $path, $headers, cookies: [SESSION_COOKIE => $session], rawQuery: $query);
}

/** A client router's request for a page's payload. */
function rsc(string $path, array $more = []): Request
{
    return visit($path, ['rsc' => '1', 'accept' => 'text/x-component', ...$more]);
}

/** @param array<string, string> $more */
function action(string $name, array $more = []): Request
{
    return new Request('POST', "/_action/{$name}", [
        'content-type' => 'application/json',
        'accept' => 'application/json',
        VerifyCsrfToken::HEADER => 't',
        ...$more,
    ], cookies: [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => 't']);
}

beforeEach(function () {
    Seen::$previous = [];
});

describe('what is recorded', function () {
    it('records a document load, with its query, and hands it to the next request', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);

        $kernel->handle(visit('/notes/drafts', query: 'sort=new'), page());
        $kernel->handle(visit('/notes'), page($told));

        expect($urls->writes)->toBe(['/notes/drafts?sort=new', '/notes'])
            ->and(Seen::$previous)->toBe(['/notes/drafts?sort=new'])
            ->and($told)->toBe('/notes/drafts?sort=new');
    });

    it('records a client visit', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);

        $kernel->handle(rsc('/notes/drafts'), page());

        expect($urls->writes)->toBe(['/notes/drafts']);
    });

    // "Prefetch requests skip side effects like previous-URL tracking."
    it('records nothing for a prefetch, a partial reload or a poll', function (array $headers) {
        $urls = new SessionUrls();
        $kernel = kernel($urls);
        $kernel->handle(visit('/notes'), page());

        $response = $kernel->handle(rsc('/notes/drafts', $headers), page());

        expect($response->status)->toBe(200)
            ->and($urls->writes)->toBe(['/notes'])
            ->and($urls->held['session-a'])->toBe('/notes');
    })->with([
        'a prefetch' => [['purpose' => 'prefetch']],
        'a partial reload' => [['x-page' => 'notes/drafts', 'x-only' => 'page']],
        'a partial reload by exclusion' => [['x-page' => 'notes/drafts', 'x-except' => 'page']],
        'a background poll' => [['x-background' => '1']],
    ]);

    // A redirect is not somewhere anyone stayed; the page it leads to
    // records itself when it is loaded.
    it('records nothing for a page that redirects', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);

        $response = $kernel->handle(visit('/notes/old'), page());

        expect($response->status)->toBe(302)
            ->and($urls->writes)->toBe([]);
    });

    it('records nothing for a page that does not exist', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);

        $response = $kernel->handle(visit('/nowhere'), page());

        expect($response->status)->toBe(404)
            ->and($urls->writes)->toBe([]);
    });

    it('records nothing for an action', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);

        $response = $kernel->handle(action('notes.save'), page(), '{}');

        expect($response->status)->toBe(200)
            ->and($urls->writes)->toBe([]);
    });

    // Reloading the page already recorded is not a new visit.
    it('writes nothing when the page is the one already recorded', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);

        $kernel->handle(visit('/notes'), page());
        $kernel->handle(visit('/notes'), page());
        $kernel->handle(rsc('/notes'), page());

        expect($urls->writes)->toBe(['/notes']);
    });

    it('keeps the previous URL as it was past the longest it records', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);
        $kernel->handle(visit('/notes'), page());

        $kernel->handle(visit('/notes/drafts', query: 'q=' . str_repeat('a', PreviousUrl::MAX_BYTES)), page());

        expect($urls->writes)->toBe(['/notes']);
    });

    it('keeps one URL per session', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);

        $kernel->handle(visit('/notes', session: 'session-a'), page());
        $kernel->handle(visit('/notes/drafts', session: 'session-b'), page());

        expect($urls->held)->toBe(['session-a' => '/notes', 'session-b' => '/notes/drafts']);
    });

    // Bookkeeping, not worth the page someone asked for.
    it('serves the page when the store fails to record it, and reports the failure', function () {
        $urls = new SessionUrls();
        $urls->failOnPut = new \RuntimeException('database is locked');
        $reported = [];
        $kernel = kernel($urls, $reported);

        $response = $kernel->handle(visit('/notes'), page());

        expect($response->status)->toBe(200)
            ->and($reported)->toHaveCount(1)
            ->and($reported[0]->getMessage())->toBe('database is locked');
    });
});

describe('reading it', function () {
    it('tells an action the page the session was last on', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);
        $kernel->handle(visit('/notes/drafts'), page());

        $response = $kernel->handle(action('notes.back'), page(), '{}');

        expect(json_decode($response->body(), true)['value'])->toBe(['back' => '/notes/drafts']);
    });

    // Asked for when wanted: a store read on every request would be a
    // database read for an answer nobody wanted.
    it('asks the store nothing for a request that neither reads it nor is a visit', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);

        $quiet = static fn (): Response => Response::make(200, '<html></html>');

        $kernel->handle(action('notes.save'), $quiet, '{}');
        $kernel->handle(rsc('/notes/drafts', ['purpose' => 'prefetch']), $quiet);

        expect($urls->reads)->toBe(0);
    });

    it('asks the store once per request, however often it is read', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);

        $kernel->handle(visit('/notes'), page());

        // The loader, the responder and the Kernel's own check all read it.
        expect($urls->reads)->toBe(1);
    });

    // Whatever the store holds is used as a place to send people.
    it('refuses what the store hands back unless it is a path on this site', function (string $stored) {
        $urls = new SessionUrls();
        $urls->held['session-a'] = $stored;
        $kernel = kernel($urls);

        $kernel->handle(visit('/notes'), page($told));

        expect($told)->toBeNull();
    })->with([
        'another origin' => ['https://evil.example/'],
        'protocol-relative' => ['//evil.example/'],
        'a backslash' => ['/\\evil.example/'],
    ]);

    it('is null when the application keeps no store', function () {
        $kernel = kernel(null);

        $kernel->handle(visit('/notes'), page($told));

        expect($told)->toBeNull()
            ->and(Seen::$previous)->toBe([null])
            ->and(visit('/notes')->previousUrl())->toBeNull();
    });
});

// "Without JavaScript, fall back to redirect-back with flashed errors."
// Back is the Referer; with none to use, the page the session last loaded --
// for a form posted without script, the page the form was on.
describe('a form posted without script', function () {
    function submitted(?string $referer): Request
    {
        return new Request('POST', '/_action/notes.save', [
            'content-type' => 'application/x-www-form-urlencoded',
            'accept' => 'text/html,application/xhtml+xml',
            ...($referer === null ? [] : ['referer' => $referer]),
        ], [], ['_token' => 't'], [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => 't']);
    }

    it('goes back to the page the session last loaded when there is no Referer', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);
        $kernel->handle(visit('/notes/drafts', query: 'tab=2'), page());

        $response = $kernel->handle(submitted(null), page());

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/notes/drafts?tab=2');
    });

    it('still goes back to the Referer when there is one', function () {
        $urls = new SessionUrls();
        $kernel = kernel($urls);
        $kernel->handle(visit('/notes/drafts'), page());

        $response = $kernel->handle(submitted('https://shop.test/notes?tab=1'), page());

        expect($response->header('location'))->toBe('/notes?tab=1');
    });

    it('goes to the root with neither', function () {
        $response = kernel(new SessionUrls())->handle(submitted(null), page());

        expect($response->header('location'))->toBe('/');
    });
});
