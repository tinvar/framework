<?php

declare(strict_types=1);

/**
 * A once-shared value's hold (ADR 0065): believed only for the key it
 * names, under the session, user and auth epoch, tenant, panel and build it
 * was sent under, within its time, signed by this server for this purpose.
 * Anything else proves nothing, and the key is produced.
 */

use Tinvar\Data\SharedRegistry;
use Tinvar\Render\RouteToken;
use Tinvar\Render\SharedHold;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Support\Signer;

const HOLD_KEY = 'shared-hold-test-key';
const HOLD_NOW = 1_800_000_000;

/** @param array<string, string|null> $overrides */
function holder(array $overrides = [], string $key = HOLD_KEY): SharedHold
{
    $binding = [
        'panel' => 'admin',
        'tenant' => 'acme',
        'build' => 'build-1',
        'session' => 'session-a',
        'user' => 'u-1',
        'epoch' => '3',
        ...$overrides,
    ];

    return new SharedHold(
        new Signer($key, RouteToken::PURPOSE),
        panel: (string) $binding['panel'],
        tenant: (string) $binding['tenant'],
        build: (string) $binding['build'],
        sessionId: (string) $binding['session'],
        user: $binding['user'],
        authEpoch: (string) $binding['epoch'],
    );
}

it('proves the key it was minted for, under the same binding', function () {
    $hold = holder()->mint('motd', 600, HOLD_NOW);

    expect(holder()->held($hold, ['motd', 'other'], HOLD_NOW + 10))->toBe(['motd']);
});

it('proves nothing once anything it is bound to has changed', function (array $changed) {
    $hold = holder()->mint('motd', 600, HOLD_NOW);

    expect(holder($changed)->held($hold, ['motd'], HOLD_NOW + 10))->toBe([]);
})->with([
    'another session (signed out, or in again)' => [['session' => 'session-b']],
    'another user' => [['user' => 'u-2']],
    'nobody signed in' => [['user' => null, 'epoch' => '']],
    'the same user at a later auth epoch' => [['epoch' => '4']],
    'another tenant' => [['tenant' => 'globex']],
    'another panel' => [['panel' => '']],
    'another build' => [['build' => 'build-2']],
]);

it('proves nothing for a key this render does not read once', function () {
    $hold = holder()->mint('motd', 600, HOLD_NOW);

    expect(holder()->held($hold, ['banner'], HOLD_NOW))->toBe([]);
});

it('proves nothing once its time is up, as the server counts it', function () {
    $hold = holder()->mint('motd', 600, HOLD_NOW);

    expect(holder()->held($hold, ['motd'], HOLD_NOW + 600))->toBe([])
        ->and(holder()->held($hold, ['motd'], HOLD_NOW + 599))->toBe(['motd']);
});

it('is never minted for longer than a day', function () {
    $hold = holder()->mint('motd', 10 * SharedRegistry::MAX_HOLD, HOLD_NOW);

    expect(holder()->held($hold, ['motd'], HOLD_NOW + SharedRegistry::MAX_HOLD))->toBe([]);
});

it('proves nothing signed by another key, or for another purpose', function () {
    $other = holder(key: 'another-key')->mint('motd', 600, HOLD_NOW);
    $signer = new Signer(HOLD_KEY, RouteToken::PURPOSE);
    $claims = [
        'n' => 'admin', 'y' => 'acme', 'b' => 'build-1',
        's' => TokenClaims::hashSession($signer, 'session-a'),
        'u' => TokenClaims::hashAuth($signer, 'u-1', '3'),
        'k' => 'motd', 'i' => HOLD_NOW, 'e' => HOLD_NOW + 600,
    ];
    $routeToken = SignedToken::encode($signer, RouteToken::PURPOSE, $claims);

    expect(holder()->held($other, ['motd'], HOLD_NOW))->toBe([])
        ->and(holder()->held($routeToken, ['motd'], HOLD_NOW))->toBe([])
        // The same claims, as a hold, are believed: the purpose is what differs.
        ->and(holder()->held(SignedToken::encode($signer, SharedHold::PURPOSE, $claims), ['motd'], HOLD_NOW))->toBe(['motd']);
});

it('proves nothing for claims it was never minted with', function (array $claims) {
    $signer = new Signer(HOLD_KEY, RouteToken::PURPOSE);
    $forged = SignedToken::encode($signer, SharedHold::PURPOSE, [
        'n' => 'admin', 'y' => 'acme', 'b' => 'build-1',
        's' => TokenClaims::hashSession($signer, 'session-a'),
        'u' => TokenClaims::hashAuth($signer, 'u-1', '3'),
        'k' => 'motd', 'i' => HOLD_NOW, 'e' => HOLD_NOW + 600,
        ...$claims,
    ]);

    expect(holder()->held($forged, ['motd'], HOLD_NOW))->toBe([]);
})->with([
    'a lifetime longer than a day' => [['e' => HOLD_NOW + SharedRegistry::MAX_HOLD + 1]],
    'issued in the future' => [['i' => HOLD_NOW + 3600, 'e' => HOLD_NOW + 3700]],
    'no issue time' => [['i' => 0]],
    'a key that is not a string' => [['k' => ['motd']]],
    'an expiry that is not a number' => [['e' => (string) (HOLD_NOW + 600)]],
    'no user binding' => [['u' => null]],
]);

it('proves nothing from a header no client of ours sends', function () {
    $hold = holder()->mint('motd', 600, HOLD_NOW);

    expect(holder()->held(implode(',', array_fill(0, SharedHold::MAX_HOLDS + 1, $hold)), ['motd'], HOLD_NOW))->toBe([])
        ->and(holder()->held(str_repeat('x', SharedHold::MAX_HEADER_BYTES + 1), ['motd'], HOLD_NOW))->toBe([])
        ->and(holder()->held(null, ['motd'], HOLD_NOW))->toBe([])
        // A bad hold beside a good one costs only its own key.
        ->and(holder()->held("garbage,{$hold}", ['motd'], HOLD_NOW))->toBe(['motd']);
});

it('refuses to mint with no session to bind to', function () {
    expect(fn () => holder(['session' => '']))->toThrow(\InvalidArgumentException::class);
});
