<?php

declare(strict_types=1);

/**
 * A credential's COSE public key: ES256 and RS256 read into keys OpenSSL
 * verifies with, and everything else refused before it is stored.
 */

namespace Tinvar\Tests\Accounts\Passkeys\CoseKey;

use Tinvar\Accounts\Passkeys\CborBytes;
use Tinvar\Accounts\Passkeys\CoseKey;
use Tinvar\Accounts\Passkeys\PasskeyRefused;
use Tinvar\Tests\Accounts\Fixtures\SoftwareAuthenticator;

require_once __DIR__ . '/../Fixtures/SoftwareAuthenticator.php';

it('verifies an ES256 signature, in DER, with the key it reads', function () {
    $authenticator = SoftwareAuthenticator::es256();
    $key = CoseKey::fromBytes($authenticator->coseBytes());
    $signature = $authenticator->sign('signed bytes');

    expect($key->algorithm)->toBe(CoseKey::ES256)
        ->and($key->toPem())->toStartWith('-----BEGIN PUBLIC KEY-----')
        ->and($key->verify('signed bytes', $signature))->toBeTrue()
        ->and($key->verify('other bytes', $signature))->toBeFalse()
        ->and($key->verify('signed bytes', ''))->toBeFalse()
        ->and($key->verify('signed bytes', 'not a DER signature'))->toBeFalse();
});

it('verifies an RS256 signature with the key it reads', function () {
    $authenticator = SoftwareAuthenticator::rs256();
    $key = CoseKey::fromCbor($authenticator->coseKey());
    $signature = $authenticator->sign('signed bytes');

    expect($key->algorithm)->toBe(CoseKey::RS256)
        ->and($key->verify('signed bytes', $signature))->toBeTrue()
        ->and($key->verify('signed bytez', $signature))->toBeFalse();
});

it('refuses an RSA key shorter than 2048 bits', function () {
    $short = SoftwareAuthenticator::rs256(1024);

    expect(fn () => CoseKey::fromCbor($short->coseKey()))->toThrow(PasskeyRefused::class);
});

it('refuses a point that is not on P-256', function () {
    $map = SoftwareAuthenticator::es256()->coseKey();
    $map[-3] = new CborBytes(str_repeat("\x01", 32));

    expect(fn () => CoseKey::fromCbor($map))->toThrow(PasskeyRefused::class);
});

it('refuses other algorithms and keys whose parts do not fit', function (\Closure $change) {
    $map = $change(SoftwareAuthenticator::es256()->coseKey());

    expect(fn () => CoseKey::fromCbor($map))->toThrow(PasskeyRefused::class);
})->with([
    'EdDSA' => [fn (array $m) => [1 => 1, 3 => -8, -1 => 6, -2 => new CborBytes(str_repeat('x', 32))]],
    'ES384' => [fn (array $m) => [...$m, 3 => -35]],
    'an EC key declared RS256' => [fn (array $m) => [...$m, 3 => -257]],
    'another curve' => [fn (array $m) => [...$m, -1 => 2]],
    'a short x' => [fn (array $m) => [...$m, -2 => new CborBytes(str_repeat("\x01", 31))]],
    'x as text' => [fn (array $m) => [...$m, -2 => str_repeat('a', 32)]],
    'no algorithm' => [fn (array $m) => array_diff_key($m, [3 => true])],
]);

it('refuses bytes that are not one COSE map', function () {
    $bytes = SoftwareAuthenticator::es256()->coseBytes();

    expect(fn () => CoseKey::fromBytes($bytes . "\x00"))->toThrow(PasskeyRefused::class)
        ->and(fn () => CoseKey::fromBytes("\x01"))->toThrow(PasskeyRefused::class);
});
