<?php

declare(strict_types=1);

/**
 * #[Always] and #[Optional] at the router: which segments a partial reload
 * renders, and which paths reach the encoder.
 *
 * ADR 0045. A partial reload renders whole segments (ADR 0015), so an always
 * field -- "included even in partial requests" -- is its segment rendered on
 * every partial reload, whether X-Only named it or X-Except left it out. An
 * optional field is resolved only for the paths a partial reload named,
 * which travel to the encoder on the payload context; a full render carries
 * none.
 */

namespace Tinvar\Tests\Routing\AlwaysSegment;

use Tinvar\Attributes\Always;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Optional;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

final class Ran
{
    /** @var list<string> */
    public static array $events = [];
}

final readonly class ShellData extends Data
{
    public function __construct(public string $name) {}
}

/** The header's badge: fresh on every partial reload of any page under it. */
final readonly class InboxData extends Data
{
    public function __construct(#[Always] public int $unread) {}
}

final readonly class ThreadPageData extends Data
{
    /** @param Lazy<list<string>> $history */
    public function __construct(
        public string $subject,
        #[Optional]
        public Lazy $history,
    ) {}
}

final readonly class StampedPageData extends Data
{
    public function __construct(#[Always] public string $stamp) {}
}

#[Layout('/', view: 'app/layout')]
final class ShellLayout
{
    public function __invoke(): ShellData
    {
        Ran::$events[] = 'layout:shell';

        return new ShellData('Shell');
    }
}

#[Layout('/inbox', view: 'inbox/layout')]
final class InboxLayout
{
    public function __invoke(): InboxData
    {
        Ran::$events[] = 'layout:inbox';

        return new InboxData(3);
    }
}

#[Page('/inbox/thread', view: 'inbox/thread', name: 'inbox.thread')]
final class ThreadPage
{
    public function __invoke(): ThreadPageData
    {
        Ran::$events[] = 'page:thread';

        return new ThreadPageData('Hello', Lazy::of(static function (): array {
            Ran::$events[] = 'history';

            return ['one', 'two'];
        }));
    }
}

#[Page('/stamped', view: 'app/stamped', name: 'stamped')]
final class StampedPage
{
    public function __invoke(): StampedPageData
    {
        return new StampedPageData('now');
    }
}

function router(): Router
{
    Ran::$events = [];
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $routes = new RouteRegistry();
    $routes->registerAll([ShellLayout::class, InboxLayout::class, ThreadPage::class, StampedPage::class]);

    return new Router(
        $routes,
        new LoaderRunner(new Orm($fixture->connection, new IdentityMap('test')), static fn (string $class): object => new $class(), new AllowAllGate()),
        static fn (string $class): Middleware => new $class(),
        partialReloads: true,
    );
}

/**
 * @param array<string, string> $headers
 * @return array{LoadedRoute, array{page: mixed, layouts: array<string, mixed>}}
 */
function ask(string $path, array $headers): array
{
    $loaded = null;
    router()->handle(
        Request::get($path, $headers),
        static function (LoadedRoute $route) use (&$loaded): Response {
            $loaded = $route;

            return Response::make(200, 'payload');
        },
    );
    \assert($loaded instanceof LoadedRoute);

    return [$loaded, $loaded->encode(new Encoder())];
}

const THREAD = ['RSC' => '1', 'X-Page' => 'inbox/thread'];

describe('a segment with an #[Always] field', function () {
    it('is rendered on a partial reload that names another, in its place in the chain', function () {
        [$loaded, $encoded] = ask('/inbox/thread', [...THREAD, 'X-Only' => 'page.subject']);

        expect($loaded->segments)->toBe(['inbox', 'page'])
            ->and($encoded['layouts'])->toBe(['inbox' => ['unread' => 3]]);
    });

    it('is rendered when X-Except leaves it out by name, as an always prop is', function () {
        [$loaded] = ask('/inbox/thread', [...THREAD, 'X-Except' => 'inbox']);

        expect($loaded->segments)->toBe(['app', 'inbox', 'page']);
    });

    it('is rendered when only a layout above it is named', function () {
        [$loaded] = ask('/inbox/thread', [...THREAD, 'X-Only' => 'app']);

        expect($loaded->segments)->toBe(['app', 'inbox']);
    });

    it('is the page itself, when the page\'s data has one', function () {
        [$loaded] = ask('/stamped', ['RSC' => '1', 'X-Page' => 'app/stamped', 'X-Only' => 'app']);

        expect($loaded->segments)->toBe(['app', 'page']);
    });

    it('changes nothing about a full render', function (array $headers) {
        [$loaded] = ask('/inbox/thread', $headers);

        expect($loaded->segments)->toBeNull();
    })->with([
        'a document' => [['X-Only' => 'page']],
        'a payload naming nothing' => [['RSC' => '1']],
        'another page\'s reload' => [['RSC' => '1', 'X-Page' => 'inbox/other', 'X-Only' => 'page']],
    ]);
});

describe('an #[Optional] field', function () {
    it('is resolved for a partial reload naming it, after every loader has run', function () {
        [$loaded, $encoded] = ask('/inbox/thread', [...THREAD, 'X-Only' => 'page.history']);

        expect($loaded->context->only)->toBe(['page.history'])
            ->and($encoded['page'])->toBe(['subject' => 'Hello', 'history' => ['one', 'two']])
            ->and(Ran::$events)->toBe(['layout:shell', 'layout:inbox', 'page:thread', 'history']);
    });

    it('is sent unasked for, and its resolver never runs, when X-Only does not name it', function () {
        [, $encoded] = ask('/inbox/thread', [...THREAD, 'X-Only' => 'page']);

        expect($encoded['page']['history'])->toBe(['$optional' => 'page.history'])
            ->and(Ran::$events)->not->toContain('history');
    });

    // "Never on full renders": a path that does not make a partial reload
    // -- another page's, a document's -- asks for nothing.
    it('is never resolved on a full render, whatever X-Only says', function (array $headers) {
        [$loaded, $encoded] = ask('/inbox/thread', $headers);

        expect($loaded->context->only)->toBe([])
            ->and($encoded['page']['history'])->toBe(['$optional' => 'page.history'])
            ->and(Ran::$events)->not->toContain('history');
    })->with([
        'a document' => [['X-Only' => 'page.history']],
        'no X-Page' => [['RSC' => '1', 'X-Only' => 'page.history']],
        'another page\'s X-Page' => [['RSC' => '1', 'X-Page' => 'inbox/other', 'X-Only' => 'page.history']],
        'an unreadable list' => [['RSC' => '1', 'X-Page' => 'inbox/thread', 'X-Only' => 'page.history,<script>']],
    ]);
});
