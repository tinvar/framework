<?php

declare(strict_types=1);

/**
 * The shop's dev toolbar, through the real renderer: a page drawn with the
 * toolbar under the response's nonce, and the endpoint handing back what the
 * renderer was given -- the props, the query ticks, every frame decoded --
 * with the timings; for the page and for the client navigation after it.
 *
 * And the other half: outside dev not a trace of it, a production marker
 * refusing it at boot, and no build of the shop containing a line of it.
 *
 * Skipped when the shop cannot be built.
 */

namespace Tinvar\Tests\Integration\ShopDevToolbar;

use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Dev\DevToolbar;
use Tinvar\Dev\Recording;
use Tinvar\Http\DevOverlay;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Support\PrivateDirectory;

const KEY = 'shop-dev-toolbar-test-key-32-bytes!!';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

function run(): string
{
    $directory = PrivateDirectory::create('tinvar-dev-shop');
    DevToolbar::prepare($directory);
    register_shutdown_function(static fn () => PrivateDirectory::remove($directory));

    return $directory;
}

/** A browser of one on this machine, against the shop in dev with its toolbar. */
final class Developer
{
    /** @var array<string, string> */
    public array $cookies = [];

    public readonly Kernel $kernel;

    public function __construct(string $socket, bool $dev = true)
    {
        $toolbar = $dev ? DevToolbar::fromEnvironment(
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
            env: ['TINVAR_ENV' => 'local', DevToolbar::ENV => run(), 'TINVAR_DEV' => '1'],
        ) : null;
        $this->kernel = Kernel::fromEnvironment($socket, key: KEY, database: Shop::database(), dev: $dev, toolbar: $toolbar);
    }

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = ['accept' => 'text/html']): Response
    {
        $response = $this->kernel->handle(new Request('GET', $path, ['host' => '127.0.0.1:6123', ...$headers], [], [], $this->cookies, null, '127.0.0.1'));
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }

    /** @return array<string, mixed> */
    public function toolbar(string $path, string $token): array
    {
        $response = $this->get($path, ['accept' => 'application/json', DevToolbar::HEADER => $token]);
        expect($response->status)->toBe(200, $response->body());
        $body = json_decode($response->body(), true);
        \assert(\is_array($body));

        return $body;
    }
}

function tokenIn(Response $response): string
{
    preg_match('#<script type="application/json" id="' . DevToolbar::ID . '-config">(.*?)</script>#s', $response->body(), $m);
    $config = json_decode($m[1] ?? 'null', true);
    expect($config)->toBeArray('the toolbar is drawn into the page');
    \assert(\is_array($config) && \is_string($config['token']));

    return $config['token'];
}

function served(\Closure $drive): void
{
    $renderer = Renderer::start('msgpack', 'dev-toolbar');
    try {
        $drive($renderer->socketPath);
    } finally {
        $renderer->stop();
    }
}

it('draws the toolbar into a dev page, and hands back what the renderer was given', function () {
    served(function (string $socket): void {
        $developer = new Developer($socket);
        $catalog = $developer->get('/');
        $nonce = DevOverlay::nonceOf($catalog->header('content-security-policy'));

        expect($catalog->status)->toBe(200)
            ->and($nonce)->not->toBeNull()
            ->and($catalog->body())->toContain('<script nonce="' . $nonce . '">// The dev toolbar\'s script');
        $token = tokenIn($catalog);

        // A product page, whose availability is a query over the socket, and
        // a client navigation back to the catalog.
        preg_match('#href="(/products/[^"]+)"#', $catalog->body(), $link);
        expect($link)->not->toBe([]);
        $product = $developer->get($link[1]);
        // On the build the page metadata names, as the client router sends it.
        preg_match('#"version":"([^"]+)"#', $catalog->body(), $version);
        $payload = $developer->get('/', ['accept' => 'text/x-component', 'rsc' => '1', 'x-version' => $version[1] ?? '']);
        expect($product->status)->toBe(200)
            ->and($payload->status)->toBe(200)
            ->and($payload->body())->not->toContain(DevToolbar::ID);

        $requests = $developer->toolbar(DevToolbar::PATH, $token)['requests'];
        expect(array_map(static fn (array $r): string => "{$r['kind']} {$r['path']}", $requests))
            ->toBe(['payload /', "document {$link[1]}", 'document /']);

        // The catalog: the loader data the renderer was given, whole.
        $page = $developer->toolbar(DevToolbar::PATH . '/' . $requests[2]['id'], $token)['request'];
        $render = $page['frames'][0];
        expect($render)->toMatchArray(['dir' => 'out', 't' => 'render'])
            ->and($render['body']['route'])->toBe('shop/catalog')
            ->and(json_encode($render['body']['data']))->toContain('"products"')
            ->and($render['body']['nonce'])->toBe(Recording::REDACTED)
            ->and($render['body']['csrf'])->toBe(Recording::REDACTED)
            ->and($render['body']['page']['routeToken'] ?? Recording::REDACTED)->toBe(Recording::REDACTED)
            ->and(array_column($page['frames'], 't'))->toContain('chunk')
            ->and(end($page['frames'])['t'])->toBe('done')
            ->and($page['timings'])->toHaveCount(1)
            ->and($page['timings'][0]['totalMs'])->toBeGreaterThan(0.0)
            ->and($page['timings'][0]['waitingOnRendererMs'])->toBeGreaterThan(0.0)
            ->and($page['timings'][0]['bytes'])->toBeGreaterThan(1000)
            ->and($page['ms'])->toBeGreaterThanOrEqual($page['timings'][0]['totalMs']);

        // The product: its availability asked for in a tick, and answered.
        $detail = $developer->toolbar(DevToolbar::PATH . '/' . $requests[1]['id'], $token)['request'];
        $ticks = array_values(array_filter($detail['frames'], static fn (array $f): bool => $f['t'] === 'query'));
        expect($ticks)->not->toBe([])
            ->and(array_column($ticks[0]['body']['q'], 'name'))->toContain('products.availability')
            ->and(array_map(static fn (array $f): string => "{$f['dir']}:{$f['t']}", $detail['frames']))->toContain('out:result')
            ->and($detail['timings'][0]['queryBatches'])->toBeGreaterThanOrEqual(1);

        // Where each slot of its props came from, with its Data and
        // file:line (ADR 0062): the page's loader -- the shop's layout loads
        // nothing, and reads what it shows as shared data, in the ticks.
        expect(array_map(static fn (array $o): string => "{$o['kind']} {$o['slot']} {$o['loader']} {$o['data']}", $detail['origins']))
            ->toBe(['page page Shop\Http\Pages\Catalog\ProductPage::__invoke Shop\Http\Data\Catalog\ProductPageData'])
            ->and($detail['origins'][0]['site'])->toMatch('#/examples/shop/app/Http/Pages/Catalog/ProductPage\.php:\d+$#')
            ->and($detail['lazyLoads'])->toBeArray()
            ->and($detail['acrossTenants'])->toBeArray()
            ->and($developer->toolbar(DevToolbar::PATH, $token)['protocol'])->toBe(DevToolbar::PROTOCOL);

        // A renderer built for production names no component behind a call,
        // the toolbar's run or not: React's owner stack is development's
        // alone, and the renderer refuses to look under NODE_ENV=production.
        foreach ($ticks as $tick) {
            foreach ($tick['body']['q'] as $call) {
                expect($call)->not->toHaveKey('by');
            }
        }

        // Nothing in any of it that would let someone act as the visitor.
        $all = json_encode([$page, $detail], JSON_THROW_ON_ERROR);
        expect($all)->not->toContain((string) $nonce)
            ->and($all)->not->toContain($developer->cookies['__Host-XSRF-TOKEN'] ?? 'no-csrf-cookie')
            ->and($all)->not->toContain($developer->cookies[Kernel::SESSION_COOKIE]);
    });
});

it('reads nothing for another visitor\'s session', function () {
    served(function (string $socket): void {
        $developer = new Developer($socket);
        $token = tokenIn($developer->get('/'));

        $stranger = new Developer($socket);
        $stranger->get('/');
        $response = $stranger->get(DevToolbar::PATH, ['accept' => 'application/json', DevToolbar::HEADER => $token]);

        expect($response->status)->toBe(403);
    });
});

// Outside dev not a trace of it, though the variable is left in the environment.
it('draws nothing and answers nothing outside dev', function () {
    served(function (string $socket): void {
        $directory = run();
        putenv(DevToolbar::ENV . "={$directory}");
        try {
            $visitor = new Developer($socket, dev: false);
            $page = $visitor->get('/');
            $endpoint = $visitor->get(DevToolbar::PATH, ['accept' => 'application/json']);
        } finally {
            putenv(DevToolbar::ENV);
        }

        expect($page->status)->toBe(200)
            ->and($page->body())->not->toContain(DevToolbar::ID)
            ->and($page->body())->not->toContain(DevToolbar::PATH)
            ->and($endpoint->status)->toBe(404)
            ->and(glob("{$directory}/r-*.json"))->toBe([]);
    });
});

it('refuses to boot with the toolbar asked for in production', function (string $marker) {
    $directory = run();
    $before = getenv($marker);
    putenv(DevToolbar::ENV . "={$directory}");
    putenv("{$marker}=production");
    // A production build has its discovery manifest (ADR 0146).
    putenv('TINVAR_BUILD_DIR=' . Shop::productionBuild());
    try {
        expect(fn () => Kernel::fromEnvironment('/tmp/no.sock', key: KEY, database: Shop::database(), dev: false))
            ->toThrow(\RuntimeException::class, "TINVAR_DEV_TOOLBAR is refused: {$marker}=production");
    } finally {
        putenv(DevToolbar::ENV);
        // TINVAR_ENV=testing back, as phpunit.xml set it (ADR 0213).
        putenv($before === false ? $marker : "{$marker}={$before}");
        putenv('TINVAR_BUILD_DIR');
    }
})->with(['TINVAR_ENV', 'APP_ENV', 'NODE_ENV']);

// "never ships in production builds": the shop's build, every environment of
// it, holds none of the toolbar -- its script, its style, its endpoint.
it('is in no build of the shop', function () {
    $dist = Shop::root() . '/dist';
    $found = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS));
    $scanned = 0;
    foreach ($files as $file) {
        if (!$file instanceof \SplFileInfo || !$file->isFile() || !\in_array($file->getExtension(), ['js', 'css', 'html', 'json', 'map'], true)) {
            continue;
        }
        $scanned++;
        $contents = (string) file_get_contents($file->getPathname());
        foreach ([DevToolbar::ID, DevToolbar::PATH, DevToolbar::HEADER, 'The dev toolbar'] as $marker) {
            if (str_contains($contents, $marker)) {
                $found[] = "{$file->getPathname()}: {$marker}";
            }
        }
    }

    expect($scanned)->toBeGreaterThan(5)
        ->and(is_dir("{$dist}/client"))->toBeTrue()
        ->and(is_dir("{$dist}/rsc"))->toBeTrue()
        ->and($found)->toBe([]);
});
