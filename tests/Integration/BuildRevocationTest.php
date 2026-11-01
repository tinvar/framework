<?php

declare(strict_types=1);

/**
 * `tinvar build:revoke` and `build:floor` against the running shop: the
 * operator runs the CLI, and a Kernel booted before it -- a worker that has
 * not restarted -- refuses the build on its next request, both where a
 * client navigates and where a deferred boundary loads, through the real
 * renderer.
 */

namespace Tinvar\Tests\Integration\BuildRevocation;

use Shop\Support\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenClaims;
use Tinvar\Support\Signer;

const KEY = 'build-revocation-test-key-of-32-bytes';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/**
 * A database of each test's own, so the build history beside it is too:
 * every other test shares the default one, and would read these revocations.
 */
function database(int $customers): string
{
    return Shop::database($customers);
}

/** Runs bin/tinvar against a database, as an operator would, and expects it to succeed. */
function tinvar(string $database, string ...$arguments): void
{
    $root = \dirname(__DIR__, 4);
    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root,
        [...array_diff_key(getenv(), ['TINVAR_BUILDS_FILE' => true, 'TINVAR_BUILD' => true]), 'SHOP_DATABASE' => $database],
    );
    $output = (string) stream_get_contents($pipes[1]);

    expect(proc_close($process))->toBe(0, $output);
}

/** @param array<string, string> $cookies */
function keep(Response $response, array &$cookies): Response
{
    foreach ($response->cookies() as $name => $line) {
        $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
    }

    return $response;
}

/**
 * The demo customer signed in, and one of their orders.
 *
 * @return array{array<string, string>, string} cookies, and an order id
 */
function signedIn(Kernel $kernel, string $database): array
{
    $cookies = [];
    keep($kernel->handle(new Request('GET', '/sign-in', ['accept' => 'text/html'], cookies: $cookies)), $cookies);

    $csrf = (string) new CsrfTokens(
        new Signer(KEY, CsrfTokens::PURPOSE),
        static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
    )->for(new Request('GET', '/', cookies: $cookies));

    $signIn = keep($kernel->handle(
        new Request(
            'POST',
            '/_action/account.signIn',
            ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $csrf],
            cookies: [...$cookies, '__Host-XSRF-TOKEN' => $csrf],
        ),
        json_encode(['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/orders'], JSON_THROW_ON_ERROR),
    ), $cookies);
    expect($signIn->status)->toBe(303, $signIn->body());

    $order = new \PDO('sqlite:' . $database)->query(
        "SELECT o.id FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.email = '" . DemoAccount::EMAIL . "' LIMIT 1",
    )?->fetchColumn();

    return [$cookies, (string) $order];
}

/** The binding a minter writes for the demo customer as they are signed in now: their id and auth epoch. */
function demoAuthHash(Signer $signer, string $database): string
{
    $row = new \PDO('sqlite:' . $database)->query(
        "SELECT c.id, COALESCE(e.epoch, 0) AS epoch FROM customers c LEFT JOIN auth_epochs e ON e.customer_id = c.id WHERE c.email = '" . DemoAccount::EMAIL . "'",
    )?->fetch(\PDO::FETCH_ASSOC);

    return TokenClaims::hashAuth($signer, (string) ($row['id'] ?? ''), (string) ($row['epoch'] ?? '0'));
}

/** What the order page minted for this session while it ran build $build. */
function shippingToken(string $session, string $order, string $build, string $database, string $tenant): string
{
    $signer = new Signer(KEY, FragmentToken::PURPOSE);

    return new FragmentToken(
        component: 'ShippingUpdates',
        props: ['order' => $order],
        route: 'orders.show',
        params: ['order' => $order],
        panel: '',
        tenant: $tenant,
        build: $build,
        sessionHash: FragmentToken::hashSession($signer, $session),
        authHash: demoAuthHash($signer, $database),
        expiresAt: time() + 3600,
        issuedAt: time(),
    )->encode($signer);
}

function navigation(string $version): Request
{
    return Request::get('/', ['rsc' => '1', 'accept' => 'text/x-component', 'x-version' => $version]);
}

it('refuses a build revoked from the CLI on the next request, without a restart', function () {
    $database = database(customers: 20);
    // Released before this server's own build, and retained for its clients.
    tinvar($database, 'build:current', 'r-old');
    tinvar($database, 'build:current', 'dev');

    $renderer = Renderer::start('msgpack', 'build-revocation');
    try {
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database);
        [$cookies, $order] = signedIn($kernel, $database);
        $session = (string) Kernel::sessionFrom($cookies[Kernel::SESSION_COOKIE], KEY);
        $fragment = new Request(
            'GET',
            FragmentEndpoint::PREFIX . shippingToken($session, $order, 'r-old', $database, TenantClaim::current($kernel->orm)),
            ['rsc' => '1', 'accept' => 'text/x-component'],
            cookies: $cookies,
        );

        $navigated = $kernel->handle(navigation('r-old'));
        $loaded = $kernel->handle($fragment);

        tinvar($database, 'build:revoke', 'r-old');

        $refusedNavigation = $kernel->handle(navigation('r-old'));
        $refusedFragment = $kernel->handle($fragment);
        $current = $kernel->handle(navigation('dev'));
    } finally {
        $renderer->stop();
    }

    expect($navigated->status)->toBe(200, 'retained, before the revocation')
        ->and($loaded->status)->toBe(200, $loaded->body())
        ->and($loaded->body())->toContain('data-shipping-updates')
        // A full page load is the only thing that replaces code the browser holds.
        ->and($refusedNavigation->status)->toBe(409)
        ->and($refusedNavigation->header('x-location'))->toBe('/')
        ->and($refusedFragment->status)->toBe(404)
        ->and($current->status)->toBe(200, 'the current build is untouched');
});

it('refuses every build below a floor set from the CLI', function () {
    $database = database(customers: 21);
    tinvar($database, 'build:current', 'f-1');
    tinvar($database, 'build:current', 'f-2');
    tinvar($database, 'build:current', 'dev');

    $renderer = Renderer::start('msgpack', 'build-floor');
    try {
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database);
        $before = $kernel->handle(navigation('f-1'));

        tinvar($database, 'build:floor', 'f-2');

        $below = $kernel->handle(navigation('f-1'));
        $at = $kernel->handle(navigation('f-2'));
    } finally {
        $renderer->stop();
    }

    expect($before->status)->toBe(200)
        ->and($below->status)->toBe(409)
        ->and($at->status)->toBe(200);
});
