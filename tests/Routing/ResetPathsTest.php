<?php

declare(strict_types=1);

/**
 * `X-Reset` through the router, and a loaded route encoded for the renderer.
 *
 * ADR 0020. "Re-resolve paths without merge labels": a merged field the
 * client names is sent to replace what it shows rather than be added to it.
 * The router reads the paths; LoadedRoute::encode() roots every segment's
 * fields at that segment's key, which is how the client names them.
 */

namespace Tinvar\Tests\Routing\ResetPaths;

use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Merge;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Data\Scroll;
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

final readonly class InboxData extends Data
{
    /** @param list<string> $pinned */
    public function __construct(#[Merge] public array $pinned) {}
}

final readonly class MessagesPageData extends Data
{
    /** @param Scroll<string> $messages */
    public function __construct(public Scroll $messages) {}
}

#[Layout('/inbox', view: 'inbox/layout')]
final class InboxLayout
{
    public function __invoke(): InboxData
    {
        return new InboxData(['welcome']);
    }
}

#[Page('/inbox/messages', view: 'inbox/messages', name: 'inbox.messages')]
final class MessagesPage
{
    public function __invoke(Request $request): MessagesPageData
    {
        return new MessagesPageData(Scroll::paginate(['hello'], Scroll::pageFrom($request->query['page'] ?? null), 1, 3));
    }
}

/**
 * @param array<string, string> $headers
 * @return array<string, mixed> what the renderer would be handed
 */
function encoded(array $headers, bool $partialReloads = true): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $routes = new RouteRegistry();
    $routes->registerAll([InboxLayout::class, MessagesPage::class]);

    $router = new Router(
        $routes,
        new LoaderRunner(new Orm($fixture->connection, new IdentityMap('test')), static fn (string $class): object => new $class(), new AllowAllGate()),
        static fn (string $class): Middleware => new $class(),
        partialReloads: $partialReloads,
    );

    $out = [];
    $router->handle(
        Request::get('/inbox/messages', $headers, ['page' => '2']),
        static function (LoadedRoute $loaded) use (&$out): Response {
            $out = $loaded->encode(new Encoder());

            return Response::make(200, '');
        },
    );

    return $out;
}

it('roots each segment\'s fields at its key, as the client names them', function () {
    $data = encoded(['RSC' => '1']);

    expect($data['layouts']['inbox']['pinned']['$merge'])->toBe('inbox.pinned')
        ->and($data['page']['messages']['$scroll'])->toBe('page.messages')
        ->and($data['page']['messages']['page'])->toBe(2);
});

it('marks the fields X-Reset names to replace, and no others', function () {
    $data = encoded(['RSC' => '1', 'X-Reset' => 'page.messages']);

    expect($data['page']['messages']['reset'])->toBeTrue()
        ->and($data['layouts']['inbox']['pinned']['reset'])->toBeFalse();
});

// Merging is something only a client router's partial reload does, so the
// header only matters on a payload -- with or without partial reloads on.
it('reads X-Reset on any payload request, and never for a document', function (array $headers, bool $partialReloads, bool $reset) {
    $data = encoded([...$headers, 'X-Reset' => 'inbox.pinned'], $partialReloads);

    expect($data['layouts']['inbox']['pinned']['reset'])->toBe($reset);
})->with([
    'a payload' => [['RSC' => '1'], true, true],
    'a payload, partial reloads off' => [['RSC' => '1'], false, true],
    'a document' => [[], true, false],
]);

it('takes nothing from a list it cannot read whole', function (string $list) {
    $data = encoded(['RSC' => '1', 'X-Reset' => $list]);

    expect($data['page']['messages']['reset'])->toBeFalse();
})->with([
    'a malformed path beside it' => ['page.messages, ../etc'],
    'too many' => [implode(',', array_fill(0, 65, 'page.messages'))],
]);
