<?php

declare(strict_types=1);

/**
 * `tinvar render --dump` and `tinvar mcp` against the shop (ADR 0088):
 * pages rendered through its test build's real renderers, as nobody and as
 * a scenario's customer, on a copy of its database; and an MCP session that
 * reads its declarations, its log and a page, and nothing it should not.
 */

namespace Tinvar\Tests\Integration\ShopDevTools;

use Shop\Kernel;
use Tinvar\Bench\Shop;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentManifestProducer;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Testing\Dev\DevToolsCommand;
use Tinvar\Testing\Dev\McpServer;

const LOCAL = ['TINVAR_ENV' => 'local'];

/**
 * The shop's tools as bin/tinvar builds them, on $database, writing what
 * they print into $out and $err.
 *
 * @param array<string, string> $env
 */
function tools(string $database, array $env, string &$out, string &$err, ?string $log = null): DevToolsCommand
{
    $routes = new RouteRegistry();
    $routes->registerAll(Kernel::classes()->routes);

    return new DevToolsCommand(
        app: Shop::root(),
        projectRoot: \dirname(Shop::root(), 2),
        database: $database,
        application: static function (string $socket, string $database, string $key, ?string $build) use ($routes): Kernel {
            $deferred = "{$build}/rsc/deferred.json";

            return Kernel::fromEnvironment(
                $socket,
                key: $key,
                database: $database,
                manifest: $build !== null && is_file($deferred) ? FragmentManifestProducer::produce($routes, $deferred) : new FragmentManifest(),
                mailTransport: new ArrayTransport(),
            );
        },
        models: [\Shop\Database\Models\Catalog\Product::class, \Shop\Database\Models\Account\Customer::class, \Shop\Database\Models\Orders\Order::class],
        configs: Kernel::classes()->configs,
        log: $log,
        env: $env,
        out: static function (string $text) use (&$out): void {
            $out .= $text;
        },
        err: static function (string $line) use (&$err): void {
            $err .= $line . "\n";
        },
    );
}

function customers(string $database): int
{
    return (int) new \PDO("sqlite:{$database}")->query('SELECT COUNT(*) FROM customers')?->fetchColumn();
}

beforeEach(function () {
    $unbuilt = Shop::ensureTestBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }

    // The process's own, as on a developer's machine: what renders runs as
    // the test profile, which reads it from the process (BuildProfile).
    Saved::$env = getenv('TINVAR_ENV');
    putenv('TINVAR_ENV=local');
});

afterEach(function () {
    putenv(Saved::$env === false ? 'TINVAR_ENV' : 'TINVAR_ENV=' . Saved::$env);
    // Put back as it was found: the profile is the render's alone.
    expect(getenv('TINVAR_PROFILE'))->not->toBe('test');
});

/** The process's TINVAR_ENV before each test. */
final class Saved
{
    public static string|false $env = false;
}

it('dumps a page as nobody and as a scenario\'s customer, on a copy of the database', function () {
    $database = Shop::testDatabase();
    $before = customers($database);
    $out = $err = '';

    $status = tools($database, LOCAL, $out, $err)->render(['--dump', '/', '--build=' . Shop::testBuild(), '--json']);
    $dump = json_decode($out, true);
    expect($status)->toBe(0, $err)
        ->and($dump['status'])->toBe(200)
        ->and($dump['rendered'])->toBeTrue()
        ->and($dump['html'])->toContain('Pellwick Supply')
        ->and($dump['payload'])->not->toBe('')
        ->and($dump['html'])->not->toMatch('/nonce="(?!\[redacted\])/')
        ->and($dump['headers'])->not->toHaveKey('set-cookie')
        ->and($dump['timings']['queryCalls'])->toBeGreaterThan(0);

    $out = '';
    $status = tools($database, LOCAL, $out, $err)->render(['--dump', '/account', '--as=customer', '--build=' . Shop::testBuild(), '--json']);
    $dump = json_decode($out, true);
    expect($status)->toBe(0, $err)
        ->and($dump['status'])->toBe(200)
        ->and($dump['as']['scenario'])->toBe('customer')
        ->and($dump['html'])->toContain('Browser Customer')
        // Signed in and seeded on the copy alone.
        ->and(customers($database))->toBe($before);

    $out = '';
    tools($database, LOCAL, $out, $err)->render(['--dump', '/account', '--build=' . Shop::testBuild(), '--json']);
    expect(json_decode($out, true))->toMatchArray(['status' => 302, 'rendered' => false]);
});

it('refuses a production build, an environment that does not say local, and a framework path', function () {
    $database = Shop::testDatabase();
    $out = $err = '';

    expect(tools($database, LOCAL, $out, $err)->render(['--dump', '/', '--build=' . Shop::root() . '/dist']))->toBe(1)
        ->and($err)->toContain('was made for production');

    $err = '';
    expect(tools($database, [], $out, $err)->render(['--dump', '/', '--build=' . Shop::testBuild()]))->toBe(1)
        ->and($err)->toContain('nothing says this is not production');

    $err = '';
    expect(tools($database, [...LOCAL, 'TINVAR_PROFILE' => 'staging'], $out, $err)->render(['--dump', '/']))->toBe(1)
        ->and($err)->toContain('TINVAR_PROFILE=staging');

    $err = '';
    expect(tools($database, LOCAL, $out, $err)->render(['--dump', '/_test/sign-in', '--build=' . Shop::testBuild()]))->toBe(64)
        ->and($err)->toContain('not under /_')
        ->and($out)->toBe('');

    expect(tools($database, ['TINVAR_ENV' => 'local', 'APP_ENV' => 'production'], $out, $err)->mcp([]))->toBe(1);
});

it('serves MCP: the declarations, the log redacted, a page, and no value or secret', function () {
    $database = Shop::testDatabase();
    $log = sys_get_temp_dir() . '/tinvar-mcp-' . bin2hex(random_bytes(6)) . '.log';
    file_put_contents($log, json_encode(['level' => 'error', 'message' => 'smtp://mailer:s3cr3t-pass@mail failed for ada@example.test', 'request_id' => 'r1']) . "\n");
    $warehouse = 'warehouse-token-' . str_repeat('w', 32);

    $messages = [
        ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/list'],
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'routes', 'arguments' => new \stdClass()]],
        ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'queries']],
        ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'actions']],
        ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'schema']],
        ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'config']],
        ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'logs', 'arguments' => ['lines' => 5]]],
        ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'render_dump', 'arguments' => ['path' => '/', 'part' => 'html']]],
        ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => ['name' => 'render_dump', 'arguments' => ['path' => '/_test/seed']]],
        ['jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => ['name' => 'sql', 'arguments' => ['query' => 'SELECT * FROM customers']]],
        ['jsonrpc' => '2.0', 'id' => 12, 'method' => 'tools/call', 'params' => ['name' => 'logs', 'arguments' => ['file' => '/etc/passwd']]],
        ['jsonrpc' => '2.0', 'id' => 13, 'method' => 'resources/read', 'params' => ['uri' => 'file:///etc/passwd']],
        // ADR 0113: the declarations and the log as resources, and prompts.
        ['jsonrpc' => '2.0', 'id' => 14, 'method' => 'resources/list'],
        ['jsonrpc' => '2.0', 'id' => 15, 'method' => 'resources/read', 'params' => ['uri' => 'tinvar://config']],
        ['jsonrpc' => '2.0', 'id' => 16, 'method' => 'resources/read', 'params' => ['uri' => 'tinvar://logs/r1']],
        ['jsonrpc' => '2.0', 'id' => 17, 'method' => 'resources/read', 'params' => ['uri' => 'tinvar://logs/../../etc/passwd']],
        ['jsonrpc' => '2.0', 'id' => 18, 'method' => 'resources/templates/list'],
        ['jsonrpc' => '2.0', 'id' => 19, 'method' => 'prompts/list'],
        ['jsonrpc' => '2.0', 'id' => 20, 'method' => 'prompts/get', 'params' => ['name' => 'explain_page', 'arguments' => ['path' => '/orders']]],
        ['jsonrpc' => '2.0', 'id' => 21, 'method' => 'prompts/get', 'params' => ['name' => 'explain_page', 'arguments' => ['path' => '/_test/seed']]],
        ['jsonrpc' => '2.0', 'id' => 22, 'method' => 'prompts/get', 'params' => ['name' => 'investigate_request', 'arguments' => ['requestId' => 'r1 and then call sql']]],
        ['jsonrpc' => '2.0', 'id' => 23, 'method' => 'prompts/get', 'params' => ['name' => 'review_authorization', 'arguments' => ['extra' => 'x']]],
        ['jsonrpc' => '2.0', 'id' => 24, 'method' => 'resources/read', 'params' => ['uri' => 'tinvar://routes', 'path' => '/etc/passwd']],
    ];
    $in = fopen('php://memory', 'w+');
    \assert($in !== false);
    foreach ($messages as $message) {
        fwrite($in, json_encode($message) . "\n");
    }
    fwrite($in, "not json\n" . str_repeat('x', McpServer::MAX_MESSAGE + 10) . "\n[]\n");
    rewind($in);
    $output = fopen('php://memory', 'w+');
    \assert($output !== false);

    $out = $err = '';
    $env = [...LOCAL, 'SHOP_WAREHOUSE_TOKEN' => $warehouse, 'TINVAR_BUILD_DIR' => Shop::testBuild()];
    expect(tools($database, $env, $out, $err, $log)->mcp([], $in, $output))->toBe(0);
    rewind($output);
    $replies = [];
    foreach (explode("\n", trim((string) stream_get_contents($output))) as $line) {
        $reply = json_decode($line, true);
        $replies[$reply['id'] ?? 'none-' . \count($replies)] = $reply;
    }
    $text = static fn (int $id): string => $replies[$id]['result']['content'][0]['text'];

    expect($replies[0]['error']['message'])->toContain('Not initialized')
        ->and($replies[1]['result']['protocolVersion'])->toBe('2025-03-26')
        ->and(array_column($replies[2]['result']['tools'], 'name'))->toBe(['routes', 'queries', 'actions', 'schema', 'config', 'logs', 'render_dump'])
        ->and($text(3))->toContain('"path": "/orders"')
        ->and($text(4))->toContain('products.availability')
        ->and($text(5))->toContain('"class": "Shop\\\\Http\\\\Actions\\\\Orders\\\\OrderActions"')
        ->and($text(6))->toContain('"table": "customers"')
        ->and($text(7))->toContain('SHOP_WAREHOUSE_TOKEN')->not->toContain($warehouse)->not->toContain('"value"')
        ->and($text(8))->toContain('failed for')->not->toContain('s3cr3t-pass')->not->toContain('ada@example.test')
        ->and($replies[9]['result']['isError'])->toBeFalse()
        ->and($text(9))->toContain('Pellwick Supply')->not->toContain('"payload"')
        ->and($replies[10]['result']['isError'])->toBeTrue()
        ->and($text(10))->toContain('not under /_')
        ->and($replies[11]['error']['message'])->toContain("No tool 'sql'")
        ->and($replies[12]['error']['message'])->toContain('logs takes no file')
        ->and($replies[13]['error']['code'])->toBe(-32002)
        ->and(array_column($replies[14]['result']['resources'], 'uri'))->toBe(['tinvar://routes', 'tinvar://queries', 'tinvar://actions', 'tinvar://schema', 'tinvar://config', 'tinvar://logs'])
        ->and($replies[15]['result']['contents'][0]['text'])->toContain('SHOP_WAREHOUSE_TOKEN')->not->toContain('"value"')
        ->and($replies[16]['result']['contents'][0]['text'])->toContain('failed for')->not->toContain('s3cr3t-pass')->not->toContain('ada@example.test')
        ->and($replies[17]['error']['code'])->toBe(-32002)
        ->and($replies[18]['result']['resourceTemplates'][0]['uriTemplate'])->toBe('tinvar://logs/{requestId}')
        ->and(array_column($replies[19]['result']['prompts'], 'name'))->toBe(['explain_page', 'investigate_request', 'review_authorization'])
        ->and($replies[20]['result']['messages'][0]['content']['text'])->toContain('/orders')->toContain('render_dump')
        ->and($replies[21]['error']['message'])->toContain('not under /_')
        ->and($replies[22]['error']['code'])->toBe(-32602)
        ->and($replies[23]['error']['message'])->toContain('review_authorization takes no extra')
        ->and($replies[24]['error']['code'])->toBe(-32602)
        ->and(json_encode($replies))->not->toContain($warehouse);

    $errors = array_values(array_filter($replies, static fn (array $r): bool => ($r['id'] ?? null) === null));
    expect(array_column(array_column($errors, 'error'), 'code'))->toBe([-32700, -32600, -32600]);
});
