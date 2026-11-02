<?php

declare(strict_types=1);

/**
 * What an API token is as text (ADR 0159): `tvr_<id>_<secret>`, a prefix a
 * secret scanner finds, an id that is no secret, and 43 characters of
 * base62 of which only the SHA-256 is kept.
 */

namespace Tinvar\Tests\Api\ApiTokenSecret;

use Tinvar\Api\ApiTokenSecret;
use Tinvar\Http\Request;

const ID = '0192f1c4a5b67e3f8a9b0c1d2e3f4a5b';

it('issues a token a secret scanner finds by its prefix, and keeps the digest of its secret alone', function () {
    [$token, $digest] = ApiTokenSecret::issue(ID);

    expect($token)->toMatch('/^' . ApiTokenSecret::SCANNER_PATTERN . '\z/')
        ->and($token)->toStartWith('tvr_' . ID . '_')
        ->and($digest)->toBe(hash('sha256', substr($token, 37)))
        ->and($digest)->not->toContain(substr($token, 37))
        ->and(ApiTokenSecret::parse($token))->toBe([ID, substr($token, 37)]);
});

it('draws a new secret every time, from the whole base62 alphabet', function () {
    $secrets = [];
    for ($i = 0; $i < 200; $i++) {
        $secrets[] = substr(ApiTokenSecret::issue(ID)[0], 37);
    }

    expect(array_unique($secrets))->toHaveCount(200)
        ->and(\count(array_unique(str_split(implode('', $secrets)))))->toBe(62);
});

it('refuses an id that is not 32 lowercase hex digits', function () {
    expect(fn () => ApiTokenSecret::issue('ABC'))->toThrow(\InvalidArgumentException::class, '32 lowercase hex digits');
});

it('parses nothing that is not shaped as a token', function (string $text) {
    expect(ApiTokenSecret::parse($text))->toBeNull();
})->with([
    'empty' => [''],
    'another prefix' => ['ghp_' . ID . '_' . str_repeat('a', 43)],
    'a short secret' => ['tvr_' . ID . '_' . str_repeat('a', 42)],
    'a long secret' => ['tvr_' . ID . '_' . str_repeat('a', 44)],
    'an upper-case id' => ['tvr_' . strtoupper(ID) . '_' . str_repeat('a', 43)],
    'punctuation in the secret' => ['tvr_' . ID . '_' . str_repeat('a', 42) . '-'],
    'a trailing newline' => ['tvr_' . ID . '_' . str_repeat('a', 43) . "\n"],
]);

it('reads a bearer token from Authorization alone, the scheme in any case', function () {
    $token = ApiTokenSecret::issue(ID)[0];

    expect(ApiTokenSecret::bearerOf(new Request('GET', '/', ['authorization' => "Bearer {$token}"])))->toBe($token)
        ->and(ApiTokenSecret::bearerOf(new Request('GET', '/', ['authorization' => "bearer {$token}"])))->toBe($token)
        ->and(ApiTokenSecret::bearerOf(new Request('GET', '/', ['authorization' => "Basic {$token}"])))->toBeNull()
        ->and(ApiTokenSecret::bearerOf(new Request('GET', '/', [], ['access_token' => $token])))->toBeNull()
        ->and(ApiTokenSecret::bearerOf(new Request('GET', '/', [], [], [], ['token' => $token])))->toBeNull()
        ->and(ApiTokenSecret::presented(new Request('GET', '/', ['authorization' => "Bearer {$token}"])))->toBeTrue()
        ->and(ApiTokenSecret::presented(new Request('GET', '/', ['authorization' => 'Bearer something-else'])))->toBeFalse();
});
