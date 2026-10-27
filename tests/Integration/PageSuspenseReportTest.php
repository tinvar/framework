<?php

declare(strict_types=1);

/**
 * A page render's failed Suspense boundary, through the real renderer: the
 * shop's RSC and SSR entries, React's own error rows, the socket and PHP.
 *
 * ADR 0045. A server component that throws inside a Suspense boundary while
 * the document streams does not fail the render -- the page completes, the
 * boundary is rendered on the client -- and was only ever logged by the
 * renderer. It is now reported over the socket as a fragment's subtree is
 * (ADR 0017), logged by PHP under the digest the browser is handed, and
 * classified as a failed render is (ADR 0035).
 *
 * The failure is a real one: the product page's order panel awaits
 * `products.availability` inside a Suspense boundary, and here PHP's query
 * runner has no such query registered, so the call fails as any query that
 * cannot be answered does.
 */

namespace Tinvar\Tests\Integration\PageSuspenseReport;

use Shop\Data\CustomerData;
use Tinvar\Attributes\Shared;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Data\SharedData;
use Tinvar\Data\SharedReads;
use Tinvar\Data\SharedRegistry;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\RouteKind;
use Tinvar\Render\RenderFailure;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** The product page's loader data, as PHP would send it. */
function productPage(): array
{
    return [
        'layouts' => ['shop' => null],
        'page' => [
            'product' => [
                'id' => '0192f3a1-0000-7000-8000-000000000001',
                'sku' => 'PW-001',
                'name' => 'Brass hinge',
                'summary' => 'A hinge.',
                'description' => 'A brass hinge.',
                'category' => 'Hardware',
                'priceCents' => 1200,
            ],
            'signedIn' => false,
            'signInHref' => '/sign-in',
        ],
    ];
}

/** The shop's shared key, answered for nobody signed in. */
final class NobodySignedIn implements SharedData
{
    #[Shared]
    public function customer(): ?CustomerData
    {
        return null;
    }
}

/**
 * Renders the product page through the shop's renderer, and returns what
 * PHP's reporter was handed and what reached the browser.
 *
 * @param array<string, mixed> $extra frame fields: `rsc` for a client navigation's payload
 * @return array{list<array{\Throwable, ?string}>, string}
 */
function render(array $extra = [], bool $debug = false): array
{
    $orm = new Orm(new Fixture(racks: 1, unitsPerRack: 1)->connection, new IdentityMap('test'));
    // The header reads who is signed in: nobody, here, so the one failure
    // is the order panel's.
    $shared = new SharedReads(
        registry: new SharedRegistry([NobodySignedIn::class]),
        keys: ['customer' => true],
        resolver: static fn (string $class): object => new $class(),
        request: Request::get('/products/p-1'),
        page: new CompiledRoute(RouteKind::Page, NobodySignedIn::class, '/products/{product}', 'shop/product', null, ['products', '{product}'], ['product'], [], []),
        parameters: [],
        orm: $orm,
        auth: new AuthContext(),
    );

    $renderer = Renderer::start('msgpack', 'page-suspense-report');
    $reported = [];
    $body = '';

    try {
        $channel = new RenderChannel(
            $renderer->socketPath,
            new QueryRunner(new QueryRegistry(), $orm, new AllowAllGate()),
            new MessagePackCodec(),
            deadlineSeconds: 10.0,
            debug: $debug,
            report: static function (\Throwable $e, ?string $digest) use (&$reported): string {
                $reported[] = [$e, $digest];

                return (string) $digest;
            },
        );

        $frame = [...Frame::render('shop/product', [], productPage(), str_repeat('a', 32), 'nonce-1', ['products.availability']), ...$extra];
        $channel->render($frame, ['products.availability'], static function (string $bytes) use (&$body): void {
            $body .= $bytes;
        }, shared: $shared);
    } finally {
        $renderer->stop();
    }

    return [$reported, $body];
}

it('reports a document\'s failed Suspense boundary to PHP once, classified, and the page completes', function () {
    [$reported, $html] = render();

    // The document came back whole: its details, its closing tag.
    expect($html)->toContain('A brass hinge.')
        ->and($html)->toContain('</html>');

    // One line, though React met the failure twice: where the server
    // component threw, and again in the HTML render, off its error row.
    expect($reported)->toHaveCount(1);
    [$e, $digest] = $reported[0];
    expect($e)->toBeInstanceOf(RendererException::class)
        ->and($e->failure())->toBe(RenderFailure::QueryFailed)
        ->and($e->getMessage())->toStartWith(RenderFailure::QueryFailed->title())
        ->and($digest)->toMatch('/^[A-Za-z0-9_-]{1,64}$/');

    // The browser is handed the same reference PHP logged.
    expect($html)->toContain($digest);
});

it('reports a client navigation\'s failed Suspense boundary to PHP', function () {
    [$reported, $payload] = render(['rsc' => true]);

    expect($reported)->toHaveCount(1)
        ->and($reported[0][0]->failure())->toBe(RenderFailure::QueryFailed)
        ->and($payload)->toContain((string) $reported[0][1]);
});

it('keeps the renderer\'s own words and where it happened for debug alone', function () {
    [$quiet] = render();
    [$loud] = render(debug: true);

    expect($quiet[0][0]->stack)->toBeNull()
        ->and($quiet[0][0]->getMessage())->toEndWith(RenderFailure::QueryFailed->hint())
        ->and($loud[0][0]->stack)->not->toBeNull();
});
