<?php

declare(strict_types=1);

/**
 * Path segments bound to an int-backed enum or a float.
 *
 * A segment is always a string, and the int-backed enum's tryFrom() refused
 * one under strict types: every such path was a TypeError, so a 500, where a
 * value outside the enum should be a 404.
 */

namespace Tinvar\Tests\Routing\EnumSegment;

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

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

enum Step: int
{
    case First = 1;
    case Second = 2;
    case Back = -1;
}

final readonly class ValueData extends Data
{
    public function __construct(public string $value) {}
}

#[Page('/steps/{step}', view: 'steps/show', name: 'steps.show')]
final class StepPage
{
    public function __invoke(Step $step): ValueData
    {
        return new ValueData($step->name);
    }
}

#[Page('/ratios/{ratio}', view: 'ratios/show', name: 'ratios.show')]
final class RatioPage
{
    public function __invoke(float $ratio): ValueData
    {
        return new ValueData((string) $ratio);
    }
}

function router(): Router
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new RouteRegistry();
    $registry->registerAll([StepPage::class, RatioPage::class]);
    $runner = new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate());

    return new Router($registry, $runner, static fn (string $c): Middleware => new $c());
}

function valueAt(string $path): string
{
    $response = router()->handle(
        Request::get($path),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, (string) json_encode($l->page)),
    );

    return json_decode($response->body(), true)['value'];
}

it('binds an int-backed enum from its path segment', function (string $segment, string $case) {
    expect(valueAt("/steps/{$segment}"))->toBe($case);
})->with([
    ['1', 'First'],
    ['2', 'Second'],
    ['-1', 'Back'],
]);

it('404s on a segment that is not one of its cases', function (string $segment) {
    expect(fn () => valueAt("/steps/{$segment}"))->toThrow(NotFoundException::class);
})->with(['3', 'first', '1.0', '01x', '99999999999999999999']);

it('404s on a float segment too large to be finite', function () {
    expect(fn () => valueAt('/ratios/1e400'))->toThrow(NotFoundException::class)
        ->and(valueAt('/ratios/2.5'))->toBe('2.5');
});
