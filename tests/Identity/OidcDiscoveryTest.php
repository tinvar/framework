<?php

declare(strict_types=1);

/**
 * OpenID Connect discovery through OutboundHttp (ADR 0096): the document
 * taken only when its issuer is exactly the one asked for, every endpoint
 * https, `none` struck, and private keys struck from a key set.
 */

namespace Tinvar\Tests\Identity\OidcDiscovery;

use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Identity\OidcDiscovery;
use Tinvar\Identity\OidcDiscoveryFailed;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Outbound\StaticResolver;

const ISSUER = 'https://accounts.example';

/** @param array<string, mixed> $overrides */
function document(array $overrides = []): array
{
    return array_merge([
        'issuer' => ISSUER,
        'authorization_endpoint' => 'https://accounts.example/authorize',
        'token_endpoint' => 'https://accounts.example/token',
        'jwks_uri' => 'https://keys.accounts.example/jwks',
        'userinfo_endpoint' => 'https://accounts.example/userinfo',
        'response_types_supported' => ['code'],
        'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256', 'none'],
        'code_challenge_methods_supported' => ['S256'],
    ], $overrides);
}

/** @param array<string, array<array-key, mixed>|string> $bodies by URL */
function provider(array $bodies, int &$now = 1_800_000_000): array
{
    $http = new FakeOutboundHttp(static function (OutboundRequest $request) use ($bodies): OutboundResponse {
        $body = $bodies[$request->url] ?? null;

        return $body === null ? new OutboundResponse(404) : new OutboundResponse(200, ['content-type' => ['application/json']], \is_string($body) ? $body : (string) json_encode($body));
    });

    return [$http, new OidcDiscovery($http, static function () use (&$now): int {
        return $now;
    })];
}

it('discovers a provider at its well-known path, with no redirect followed', function () {
    [$http, $discovery] = provider([ISSUER . OidcDiscovery::PATH => document()]);
    $provider = $discovery->discover(ISSUER);

    expect($provider->issuer)->toBe(ISSUER)
        ->and($provider->tokenEndpoint)->toBe('https://accounts.example/token')
        ->and($provider->jwksUri)->toBe('https://keys.accounts.example/jwks')
        ->and($provider->signingAlgorithms)->toBe(['RS256'])
        ->and($provider->supportsPkce())->toBeTrue()
        ->and($http->sent[0]->maxRedirects)->toBe(0)
        ->and($http->sent[0]->maxResponseBytes)->toBe(OidcDiscovery::MAX_BYTES);
});

it('keeps a provider for an hour, then asks again', function () {
    $now = 1_800_000_000;
    [$http, $discovery] = provider([ISSUER . OidcDiscovery::PATH => document()], $now);
    $discovery->discover(ISSUER);
    $discovery->discover(ISSUER);
    $now += OidcDiscovery::TTL;
    $discovery->discover(ISSUER);

    expect($http->sent)->toHaveCount(2);
});

it('takes a document only when its issuer is exactly the one asked for', function (string $issuer) {
    [, $discovery] = provider([ISSUER . OidcDiscovery::PATH => document(['issuer' => $issuer])]);

    expect(fn () => $discovery->discover(ISSUER))
        ->toThrow(fn (OidcDiscoveryFailed $e) => expect($e->reason)->toBe(OidcDiscoveryFailed::ISSUER));
})->with([
    'another provider' => ['https://evil.example'],
    'a trailing slash' => [ISSUER . '/'],
    'another case' => ['https://Accounts.example'],
    'plain http' => ['http://accounts.example'],
]);

it('appends the path to an issuer with one, and compares that issuer whole', function () {
    $issuer = 'https://login.example/tenants/acme';
    [$http, $discovery] = provider([$issuer . OidcDiscovery::PATH => document(['issuer' => $issuer])]);

    expect($discovery->discover($issuer)->issuer)->toBe($issuer)
        ->and($http->sent[0]->url)->toBe('https://login.example/tenants/acme/.well-known/openid-configuration');
});

it('refuses an issuer that is not one, before anything is sent', function (string $issuer) {
    [$http, $discovery] = provider([]);

    expect(fn () => $discovery->discover($issuer))->toThrow(\InvalidArgumentException::class)
        ->and($http->sent)->toBe([]);
})->with([
    'plain http' => ['http://accounts.example'],
    'a query' => ['https://accounts.example?tenant=a'],
    'a fragment' => ['https://accounts.example#x'],
    'credentials' => ['https://user@accounts.example'],
    'a spelling the parser rewrites' => ['https://ACCOUNTS.example'],
    'not a URL' => ['accounts.example'],
]);

it('refuses an endpoint that is not https, and a document missing what a sign-in needs', function (array $overrides, string $reason) {
    [, $discovery] = provider([ISSUER . OidcDiscovery::PATH => document($overrides)]);

    expect(fn () => $discovery->discover(ISSUER))
        ->toThrow(fn (OidcDiscoveryFailed $e) => expect($e->reason)->toBe($reason));
})->with([
    'a token endpoint over http' => [['token_endpoint' => 'http://accounts.example/token'], OidcDiscoveryFailed::ENDPOINT],
    'a key set over http' => [['jwks_uri' => 'http://accounts.example/jwks'], OidcDiscoveryFailed::ENDPOINT],
    'a javascript: endpoint' => [['authorization_endpoint' => 'javascript:alert(1)'], OidcDiscoveryFailed::ENDPOINT],
    'no token endpoint' => [['token_endpoint' => null], OidcDiscoveryFailed::ENDPOINT],
    'only none' => [['id_token_signing_alg_values_supported' => ['none', 'NONE']], OidcDiscoveryFailed::DOCUMENT],
    'no response types' => [['response_types_supported' => []], OidcDiscoveryFailed::DOCUMENT],
]);

it('refuses what is not a JSON object, and a status other than 200', function () {
    [, $list] = provider([ISSUER . OidcDiscovery::PATH => '[1, 2]']);
    [, $missing] = provider([]);

    expect(fn () => $list->discover(ISSUER))->toThrow(fn (OidcDiscoveryFailed $e) => expect($e->reason)->toBe(OidcDiscoveryFailed::DOCUMENT))
        ->and(fn () => $missing->discover(ISSUER))->toThrow(fn (OidcDiscoveryFailed $e) => expect($e->reason)->toBe(OidcDiscoveryFailed::STATUS));
});

it('refuses an issuer whose name resolves inside the network', function () {
    $http = new FakeOutboundHttp(null, new OutboundPolicy(), new StaticResolver(['accounts.example' => ['10.1.2.3']]));

    expect(fn () => new OidcDiscovery($http)->discover(ISSUER))->toThrow(OutboundRefused::class)
        ->and($http->sent)->toBe([]);
});

it('keeps only public keys from a key set', function () {
    [, $discovery] = provider([
        ISSUER . OidcDiscovery::PATH => document(),
        'https://keys.accounts.example/jwks' => ['keys' => [
            ['kty' => 'RSA', 'kid' => 'a', 'n' => 'xyz', 'e' => 'AQAB'],
            ['kty' => 'oct', 'kid' => 'b', 'k' => 'c2VjcmV0'],
            ['kty' => 'RSA', 'kid' => 'c', 'n' => 'xyz', 'e' => 'AQAB', 'd' => 'private'],
            'not a key',
            ['kid' => 'no type'],
        ]],
    ]);

    expect(array_column($discovery->keys($discovery->discover(ISSUER)), 'kid'))->toBe(['a']);
});

it('asks for the key set again at most once a minute, however many unknown keys are named', function () {
    $now = 1_800_000_000;
    [$http, $discovery] = provider([
        ISSUER . OidcDiscovery::PATH => document(),
        'https://keys.accounts.example/jwks' => ['keys' => []],
    ], $now);
    $provider = $discovery->discover(ISSUER);
    $discovery->keys($provider);
    for ($i = 0; $i < 10; $i++) {
        $discovery->keys($provider, refresh: true);
    }
    $now += OidcDiscovery::REFRESH_INTERVAL + 1;
    $discovery->keys($provider, refresh: true);

    expect(array_filter($http->sent, static fn (OutboundRequest $r): bool => str_ends_with($r->url, '/jwks')))->toHaveCount(2);
});
