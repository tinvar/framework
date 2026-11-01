<?php

declare(strict_types=1);

/**
 * A <Deferred group> request through the whole path, for real: the shop's
 * Kernel, the fragment endpoint, the renderer over its socket and the shop's
 * own rsc entry (ADR 0018). The unit tests hold each half; this is where the
 * frame PHP sends and the payload the app's entry renders have to agree.
 */

namespace Tinvar\Tests\Integration\FragmentGroupPath;

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

const KEY = 'fragment-group-path-key-of-32-bytes!';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

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
function signedIn(Kernel $kernel): array
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
    // Signed in, and sent on to where the visitor was going.
    expect($signIn->status)->toBe(303, $signIn->body());

    $order = new \PDO('sqlite:' . Shop::database())->query(
        "SELECT o.id FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.email = '" . DemoAccount::EMAIL . "' LIMIT 1",
    )?->fetchColumn();

    return [$cookies, (string) $order];
}

/** The binding a minter writes for the demo customer as they are signed in now: their id and auth epoch. */
function demoAuthHash(Signer $signer): string
{
    $row = new \PDO('sqlite:' . Shop::database())->query(
        "SELECT c.id, COALESCE(e.epoch, 0) AS epoch FROM customers c LEFT JOIN auth_epochs e ON e.customer_id = c.id WHERE c.email = '" . DemoAccount::EMAIL . "'",
    )?->fetch(\PDO::FETCH_ASSOC);

    return TokenClaims::hashAuth($signer, (string) ($row['id'] ?? ''), (string) ($row['epoch'] ?? '0'));
}

/** A token the order page would have minted for this session, issued `$ago` seconds ago. */
function shippingToken(string $session, string $order, string $tenant, int $ago = 0): string
{
    $signer = new Signer(KEY, FragmentToken::PURPOSE);

    return new FragmentToken(
        component: 'ShippingUpdates',
        props: ['order' => $order],
        route: 'orders.show',
        params: ['order' => $order],
        panel: '',
        tenant: $tenant,
        build: 'dev',
        sessionHash: FragmentToken::hashSession($signer, $session),
        authHash: demoAuthHash($signer),
        expiresAt: time() + 3600,
        issuedAt: time() - $ago,
    )->encode($signer);
}

it('renders a group in one render, as a list with a subtree per boundary', function () {
    $renderer = Renderer::start('msgpack', 'fragment-group');
    try {
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database());
        [$cookies, $order] = signedIn($kernel);
        $session = (string) Kernel::sessionFrom($cookies[Kernel::SESSION_COOKIE], KEY);
        $tenant = TenantClaim::current($kernel->orm);

        // Two issues of the one boundary: two tokens, two subtrees.
        $response = $kernel->handle(new Request(
            'GET',
            FragmentEndpoint::PREFIX . shippingToken($session, $order, $tenant) . '/' . shippingToken($session, $order, $tenant, ago: 5),
            ['rsc' => '1', 'accept' => 'text/x-component'],
            cookies: $cookies,
        ));
    } finally {
        $renderer->stop();
    }

    expect($response->status)->toBe(200, $response->body())
        ->and($response->header('content-type'))->toBe('text/x-component')
        // The root row is a list of two references, one per boundary.
        ->and($response->body())->toMatch('/^0:\["\$L?[0-9a-f]+","\$L?[0-9a-f]+"\]\n/')
        ->and(substr_count($response->body(), 'data-shipping-updates'))->toBe(2)
        // Each reads who is signed in with shared(), from the one SharedReads
        // the fragment endpoint made behind the order page's chain (ADR 0065).
        ->and(substr_count($response->body(), 'Tracked for'))->toBe(2)
        ->and($response->body())->toContain(DemoAccount::NAME)
        ->and(explode(',', (string) $response->header('x-fragment-token')))->toHaveCount(2);
});
