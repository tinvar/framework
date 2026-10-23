<?php

declare(strict_types=1);

/**
 * An int path segment too long for PHP's int is a path that does not exist.
 *
 * It was cast, and the cast clamps: /pages/99999999999999999999 reached the
 * loader as page PHP_INT_MAX, a value the URL never named.
 */

namespace Tinvar\Tests\Routing\IntSegment;

use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\Data;
use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final readonly class NumberData extends Data
{
    public function __construct(public int $number) {}
}

#[Page('/pages/{number}', view: 'pages/show', name: 'pages.show')]
final class NumberedPage
{
    public function __invoke(int $number): NumberData
    {
        return new NumberData($number);
    }
}

function numberAt(string $path): int
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new RouteRegistry();
    $registry->registerAll([NumberedPage::class]);
    $router = new Router(
        $registry,
        new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
        static fn (string $c): Middleware => new $c(),
    );

    $response = $router->handle(
        Request::get($path),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, (string) json_encode($l->page)),
    );

    return json_decode($response->body(), true)['number'];
}

it('404s on an int segment past either end of the int range', function (string $segment) {
    expect(fn () => numberAt("/pages/{$segment}"))->toThrow(NotFoundException::class);
})->with(['99999999999999999999', '-99999999999999999999', '9223372036854775808']);

it('binds an int segment at the end of the range', function () {
    expect(numberAt('/pages/9223372036854775807'))->toBe(PHP_INT_MAX)
        ->and(numberAt('/pages/42'))->toBe(42);
});
