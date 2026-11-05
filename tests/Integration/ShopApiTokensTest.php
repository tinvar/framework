<?php

declare(strict_types=1);

/**
 * The shop's order API (ADR 0159) through its whole request path: a
 * customer issues an API token from their settings, and a script of theirs
 * reads their own orders with it -- over /_api and the receipt endpoint --
 * with no session, holding the token to its scopes, their own orders and
 * their auth epoch.
 */

namespace Tinvar\Tests\Integration\ShopApiTokens;

use Shop\Database\Models\Account\Customer;
use Shop\Database\Models\Orders\Order;
use Shop\Kernel;
use Shop\Support\DemoAccount;
use Tinvar\Api\ApiTokenSecret;
use Tinvar\Bench\Shop;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Support\Signer;

const KEY = 'shop-api-tokens-key-of-32-bytes!!!!';

beforeEach(function () {
    $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-api-none.sock', key: KEY, database: Shop::testDatabase());
});

function customer(Kernel $kernel, string $where, string $email = DemoAccount::EMAIL): Customer
{
    $id = (string) $kernel->orm->connection()->select("SELECT id FROM customers WHERE email {$where} ? ORDER BY id LIMIT 1", [$email])[0]['id'];
    $customer = $kernel->orm->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

/**
 * The customer's browser, signed in: its session cookie and CSRF token,
 * as the settings page would post with them.
 *
 * @return array<string, string> headers, with the cookies' header among them
 */
function browser(Kernel $kernel, Customer $customer): array
{
    $cookies = $kernel->signedIn($customer, new Request('GET', '/', ['host' => '127.0.0.1']));
    $session = $cookies[Kernel::SESSION_COOKIE];
    $csrf = (string) new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), static fn (): string => (string) Kernel::sessionFrom($session, KEY))->for(Request::get('/'));

    return ['x-xsrf-token' => $csrf, 'cookies' => json_encode([...$cookies, VerifyCsrfToken::COOKIE => $csrf], JSON_THROW_ON_ERROR)];
}

/**
 * Posts an action as the customer's browser does, through the whole
 * request path: their session, its CSRF token, the host.
 *
 * @param array<string, mixed> $input
 * @param array<string, string> $browser browser()'s
 */
function act(Kernel $kernel, array $browser, string $action, array $input): Response
{
    $cookies = (array) json_decode($browser['cookies'], true);

    return $kernel->handle(new Request('POST', "/_action/{$action}", [
        'host' => '127.0.0.1',
        'accept' => 'application/json',
        'content-type' => 'application/json',
        'x-xsrf-token' => $browser['x-xsrf-token'],
    ], [], $input, $cookies, remoteAddress: '127.0.0.1'), json_encode($input, JSON_THROW_ON_ERROR));
}

/** @param list<string> $scopes */
function issue(Kernel $kernel, Customer $customer, array $scopes = ['orders:read'], string $name = 'A script', ?array $browser = null): string
{
    $response = act($kernel, $browser ?? browser($kernel, $customer), 'account.createApiToken', ['name' => $name, 'scopes' => $scopes, 'currentPassword' => DemoAccount::PASSWORD, 'lifetime' => '30']);
    $token = body($response)['value']['token'] ?? null;
    \assert($response->status === 200 && \is_string($token), $response->body());

    return $token;
}

/** @param array<string, string> $headers */
function api(Kernel $kernel, string $operation, array $arguments, string $token, array $headers = []): Response
{
    $body = json_encode($arguments === [] ? new \stdClass() : $arguments, JSON_THROW_ON_ERROR);

    return $kernel->handle(new Request('POST', "/_api/{$operation}", [
        'host' => '127.0.0.1',
        'accept' => 'application/json',
        'content-type' => 'application/json',
        'authorization' => "Bearer {$token}",
        ...$headers,
    ], [], $arguments, [], remoteAddress: '127.0.0.1'), $body);
}

/** @param array<string, string> $headers */
function get(Kernel $kernel, string $path, array $headers = [], array $cookies = []): Response
{
    return $kernel->handle(new Request('GET', $path, ['host' => '127.0.0.1', 'accept' => 'application/json', ...$headers], [], [], $cookies, remoteAddress: '127.0.0.1'));
}

/** @return array<array-key, mixed> */
function body(Response $response): array
{
    return (array) json_decode($response->body(), true);
}

/** @return list<string> */
function ordersOf(Kernel $kernel, Customer $customer): array
{
    return array_map(static fn (array $row): string => (string) $row['id'], $kernel->orm->connection()->select('SELECT id FROM orders WHERE customer_id = ? ORDER BY placed_at DESC, id DESC', [$customer->id]));
}

/** An order of some other customer's. */
function someoneElsesOrder(Kernel $kernel, Customer $customer): string
{
    return (string) $kernel->orm->connection()->select('SELECT id FROM orders WHERE customer_id <> ? ORDER BY id LIMIT 1', [$customer->id])[0]['id'];
}

function sessionRows(Kernel $kernel): int
{
    return (int) $kernel->orm->connection()->select('SELECT COUNT(*) AS n FROM sessions')[0]['n'];
}

it('issues a token from the settings page\'s action, shown once and kept as a digest', function () {
    $token = issue($this->kernel, customer($this->kernel, '='), ['orders:read'], 'Nightly export');
    [$id, $secret] = ApiTokenSecret::parse($token) ?? throw new \LogicException('not a token');
    $row = $this->kernel->orm->connection()->select('SELECT * FROM api_tokens WHERE id = ?', [$id])[0];

    expect($row['name'])->toBe('Nightly export')
        ->and($row['secret_digest'])->toBe(hash('sha256', $secret))
        ->and(implode('|', array_map(strval(...), $row)))->not->toContain($secret);
});

// Audit (ADR 0159): a token outlives the session that made it and every
// sign-in, so a session alone -- a stolen cookie -- minted a lasting
// credential. The shop has no "confirm it's you" dialog, so the password is
// typed again on the form, confirming the session through the accounts kit
// as a sign-in's guess is counted (ADR 0162); a token lasts a year at most,
// and each one made is told to the customer by email.
it('asks for the customer\'s password before it issues a token, and lets none last past a year', function () {
    $customer = customer($this->kernel, '=');
    $browser = browser($this->kernel, $customer);
    $tokens = fn (): int => (int) $this->kernel->orm->connection()->select('SELECT COUNT(*) AS n FROM api_tokens')[0]['n'];
    $before = $tokens();
    $ask = fn (array $input): Response => act($this->kernel, $browser, 'account.createApiToken', ['name' => 'Stolen', 'scopes' => ['orders:read'], 'lifetime' => '30', ...$input]);

    $none = $ask([]);
    $wrong = $ask(['currentPassword' => 'a guess']);
    $forever = $ask(['currentPassword' => DemoAccount::PASSWORD, 'lifetime' => 'never']);
    $mail = fn (): int => \count(array_filter(
        $this->kernel->orm->connection()->select('SELECT payload FROM jobs'),
        static fn (array $row): bool => str_contains((string) $row['payload'], 'api-token-created'),
    ));
    expect($none->status)->toBe(422)
        ->and(body($none)['errors'])->toHaveKey('currentPassword')
        ->and($wrong->status)->toBe(422)
        ->and(body($wrong)['errors']['currentPassword'] ?? null)->toBe([\Shop\Http\Actions\Account\ApiTokenActions::WRONG_PASSWORD])
        ->and($forever->status)->toBe(422)
        ->and(body($forever)['errors'])->toHaveKey('lifetime')
        ->and($tokens())->toBe($before)
        ->and($mail())->toBe(0)
        ->and($ask(['currentPassword' => DemoAccount::PASSWORD])->status)->toBe(200)
        ->and($tokens())->toBe($before + 1)
        // And the customer is told, by an email naming it (ADR 0153, 0162).
        ->and($mail())->toBe(1);
});

it('offers the scopes the shop\'s API declares, and refuses any other', function () {
    $customer = customer($this->kernel, '=');

    expect($this->kernel->kernel()->apiTokens?->grantable())->toBe(['orders:cancel', 'orders:read']);
    $refused = act($this->kernel, browser($this->kernel, $customer), 'account.createApiToken', ['name' => 'x', 'scopes' => ['admin:everything'], 'currentPassword' => DemoAccount::PASSWORD, 'lifetime' => '30']);

    expect($refused->status)->toBe(422)
        ->and(body($refused)['errors'])->toHaveKey('scopes');
});

it('reads the customer\'s own orders over /_api, with no session', function () {
    $customer = customer($this->kernel, '=');
    $token = issue($this->kernel, $customer);
    $rows = sessionRows($this->kernel);

    $response = api($this->kernel, 'orders.yours', [], $token);

    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toBe('application/json')
        ->and(array_column(body($response)['orders'], 'id'))->toBe(\array_slice(ordersOf($this->kernel, $customer), 0, 50))
        ->and(body($response)['status'])->toBe('all')
        ->and($response->cookies())->toBe([])
        ->and($response->header('set-cookie'))->toBeNull()
        ->and(sessionRows($this->kernel))->toBe($rows);

    $open = api($this->kernel, 'orders.yours', ['status' => 'open'], $token);
    expect($open->status)->toBe(200)
        ->and(array_unique(array_column(body($open)['orders'], 'status')))->each->toBeIn(['placed', 'packed', 'shipped'])
        ->and(api($this->kernel, 'orders.yours', ['status' => 'lost'], $token)->status)->toBe(422);
});

it('reads one of their orders in full, and refuses another customer\'s', function () {
    $customer = customer($this->kernel, '=');
    $token = issue($this->kernel, $customer);
    $theirs = ordersOf($this->kernel, $customer)[0];
    $someoneElses = someoneElsesOrder($this->kernel, $customer);

    $own = api($this->kernel, 'orders.detail', ['order' => $theirs], $token);

    expect($own->status)->toBe(200)
        ->and(body($own)['id'])->toBe($theirs)
        ->and(body($own)['lines'])->not->toBe([])
        ->and(api($this->kernel, 'orders.detail', ['order' => $someoneElses], $token)->status)->toBe(403)
        ->and(api($this->kernel, 'orders.detail', ['order' => 'no-such-order'], $token)->status)->toBe(404);
});

it('cancels only with a token holding orders:cancel, and only what the policy allows', function () {
    $customer = customer($this->kernel, '=');
    $reader = issue($this->kernel, $customer);
    $canceller = issue($this->kernel, $customer, ['orders:cancel']);
    $placed = $this->kernel->orm->connection()->select("SELECT id FROM orders WHERE customer_id = ? AND status = 'placed' LIMIT 1", [$customer->id])[0]['id'] ?? null;
    $delivered = $this->kernel->orm->connection()->select("SELECT id FROM orders WHERE customer_id = ? AND status = 'delivered' LIMIT 1", [$customer->id])[0]['id'] ?? null;
    \assert(\is_string($placed) && \is_string($delivered));

    $refused = api($this->kernel, 'orders.cancel', ['order' => $placed], $reader);
    expect($refused->status)->toBe(403)
        ->and(body($refused)['error']['code'])->toBe('insufficient_scope')
        ->and($refused->header('www-authenticate'))->toContain('scope="orders:cancel"')
        ->and($this->kernel->orm->find(Order::class, $placed)?->status->value)->toBe('placed');

    expect(api($this->kernel, 'orders.cancel', ['order' => $delivered], $canceller)->status)->toBe(403)
        ->and(api($this->kernel, 'orders.cancel', ['order' => $placed], $canceller)->status)->toBe(200)
        ->and((string) $this->kernel->orm->connection()->select('SELECT status FROM orders WHERE id = ?', [$placed])[0]['status'])->toBe('cancelled');
});

it('hands over a receipt at the endpoint marked #[RequiresScope], for a token and never a cookie', function () {
    $customer = customer($this->kernel, '=');
    $token = issue($this->kernel, $customer);
    $order = ordersOf($this->kernel, $customer)[0];
    $cookies = $this->kernel->signedIn($customer, new Request('GET', '/', ['host' => '127.0.0.1']));

    $receipt = get($this->kernel, "/api/orders/{$order}/receipt.txt", ['authorization' => "Bearer {$token}"]);

    expect($receipt->status)->toBe(200)
        ->and($receipt->header('content-type'))->toBe('text/plain; charset=utf-8')
        ->and($receipt->body())->toContain('Receipt for order')
        ->and($receipt->cookies())->toBe([])
        ->and(get($this->kernel, "/api/orders/{$order}/receipt.txt", [], $cookies)->status)->toBe(401)
        ->and(get($this->kernel, '/api/orders/' . someoneElsesOrder($this->kernel, $customer) . '/receipt.txt', ['authorization' => "Bearer {$token}"])->status)->toBe(403);
});

it('refuses a token on the session\'s own paths', function () {
    $customer = customer($this->kernel, '=');
    $token = issue($this->kernel, $customer);
    $order = ordersOf($this->kernel, $customer)[0];

    $page = get($this->kernel, '/orders', ['authorization' => "Bearer {$token}"]);
    $receipt = get($this->kernel, "/orders/{$order}/receipt.txt", ['authorization' => "Bearer {$token}"]);

    expect($page->status)->toBe(401)
        ->and(body($page)['error']['code'])->toBe('api_token_not_accepted')
        ->and($receipt->status)->toBe(401)
        ->and($receipt->cookies())->toBe([]);
});

it('ends a token revoked, and every token when the customer signs out everywhere, but not when they sign in elsewhere', function () {
    $customer = customer($this->kernel, '=');
    $browser = browser($this->kernel, $customer);
    $revoked = issue($this->kernel, $customer, name: 'Revoked', browser: $browser);
    $kept = issue($this->kernel, $customer, name: 'Kept', browser: $browser);

    expect(act($this->kernel, $browser, 'account.revokeApiToken', ['id' => (string) ApiTokenSecret::parse($revoked)[0]])->status)->toBe(200)
        ->and(api($this->kernel, 'orders.yours', [], $revoked)->status)->toBe(401)
        ->and(api($this->kernel, 'orders.yours', [], $kept)->status)->toBe(200)
        ->and(act($this->kernel, $browser, 'account.revokeApiToken', ['id' => (string) ApiTokenSecret::parse($revoked)[0]])->status)->toBe(422);

    // Signing in on a phone moves the auth epoch, and ends no token.
    $phone = browser($this->kernel, $customer);
    expect(api($this->kernel, 'orders.yours', [], $kept)->status)->toBe(200);

    // Signing out everywhere ends them all, with every session.
    expect(act($this->kernel, $phone, 'account.signOutEverywhere', [])->status)->toBe(200)
        ->and(api($this->kernel, 'orders.yours', [], $kept)->status)->toBe(401);
});

it('records when each token was last used', function () {
    $customer = customer($this->kernel, '=');
    $token = issue($this->kernel, $customer);
    $id = (string) ApiTokenSecret::parse($token)[0];

    expect($this->kernel->orm->connection()->select('SELECT last_used_at FROM api_tokens WHERE id = ?', [$id])[0]['last_used_at'])->toBeNull();
    api($this->kernel, 'orders.yours', [], $token);
    expect($this->kernel->orm->connection()->select('SELECT last_used_at FROM api_tokens WHERE id = ?', [$id])[0]['last_used_at'])->not->toBeNull();
});
