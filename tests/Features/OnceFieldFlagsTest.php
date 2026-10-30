<?php

declare(strict_types=1);

/**
 * A loader's own #[Once] field is held by the browser until it expires, and
 * a feature flag is not among what a hold is bound to (ADR 0094). So a
 * segment whose loader read a flag -- in its body, or in a Lazy field the
 * encoder resolves -- sends its once fields to be shown and not kept, as a
 * shared value that read one is.
 */

namespace Tinvar\Tests\Features\OnceFieldFlags;

use Tinvar\Attributes\Once;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Features\FlagReads;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Lazy;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

/** Where the page's loader reads its flag, if anywhere. */
final class Reads
{
    /** 'none', 'body' or 'lazy' */
    public static string $where = 'none';
}

final readonly class TotalsData extends Data
{
    /** @param Lazy<int> $totals */
    public function __construct(
        public string $heading,
        #[Once(key: 'account.totals', until: '10m')]
        public Lazy $totals,
    ) {}
}

#[Page('/totals', view: 'totals', name: 'totals')]
final class TotalsPage
{
    public function __invoke(): TotalsData
    {
        // As Features::active() reports a read of the caller's flag.
        if (Reads::$where === 'body') {
            FlagReads::record('totals.new', true);
        }

        return new TotalsData('Totals', Lazy::of(static function (): int {
            if (Reads::$where === 'lazy') {
                FlagReads::record('totals.new', true);
            }

            return 42;
        }));
    }
}

/** @return array<string, mixed> the page's once field, as encoded */
function totals(string $where): array
{
    Reads::$where = $where;
    $fixture = new Fixture(racks: 1, unitsPerRack: 1);
    $registry = new RouteRegistry();
    $registry->register(TotalsPage::class);
    $runner = new LoaderRunner(new Orm($fixture->connection, new IdentityMap('test')), static fn (string $c): object => new $c(), new AllowAllGate());
    $router = new Router($registry, $runner, static fn (string $c): Middleware => new $c());

    $encoded = null;
    $router->handle(Request::get('/totals'), static function (LoadedRoute $loaded) use (&$encoded): Response {
        $encoded = $loaded->encode(new Encoder());

        return Response::make(200, 'ok');
    });

    return $encoded['page']['totals'];
}

it('lets the browser keep a once field whose loader read no flag', function () {
    expect(totals('none'))->toHaveKey('value', 42)
        ->and(totals('none'))->not->toHaveKey('keep');
});

it('sends a once field to be shown and not kept where its loader read a flag', function (string $where) {
    expect(totals($where))->toHaveKey('value', 42)
        ->and(totals($where)['keep'] ?? null)->toBeFalse();
})->with(['in its body' => ['body'], 'in a Lazy field' => ['lazy']]);
