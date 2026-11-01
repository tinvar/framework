<?php

declare(strict_types=1);

/**
 * An authenticator's data, read exactly: its flags and counter, the new
 * credential of a registration, and not a byte more than its structure.
 */

namespace Tinvar\Tests\Accounts\Passkeys\AuthenticatorData;

use Tinvar\Accounts\Passkeys\AuthenticatorData;
use Tinvar\Accounts\Passkeys\PasskeyRefused;
use Tinvar\Tests\Accounts\Fixtures\SoftwareAuthenticator;

require_once __DIR__ . '/../Fixtures/SoftwareAuthenticator.php';

it('reads a sign-in\'s flags and counter', function () {
    $bytes = hash('sha256', 'app.example', true) . "\x1d" . pack('N', 41);
    $data = AuthenticatorData::parse($bytes);

    expect($data->raw)->toBe($bytes)
        ->and($data->rpIdHash)->toBe(hash('sha256', 'app.example', true))
        ->and($data->signCount)->toBe(41)
        ->and($data->userPresent())->toBeTrue()
        ->and($data->userVerified())->toBeTrue()
        ->and($data->backupEligible())->toBeTrue()
        ->and($data->backedUp())->toBeTrue()
        ->and($data->hasAttestedCredential())->toBeFalse()
        ->and($data->credentialId)->toBeNull();
});

it('reads a registration\'s credential', function () {
    $authenticator = SoftwareAuthenticator::es256();
    $data = AuthenticatorData::parse($authenticator->register('challenge')['authData']);

    expect($data->hasAttestedCredential())->toBeTrue()
        ->and($data->credentialId)->toBe($authenticator->credentialId)
        ->and($data->credentialPublicKey)->toBe($authenticator->coseBytes())
        ->and($data->aaguid)->toBe(str_repeat("\xab", 16));
});

it('reads past extensions, and refuses anything after them', function () {
    $base = hash('sha256', 'app.example', true) . "\x85" . pack('N', 0);
    $extensions = SoftwareAuthenticator::cbor(['credProtect' => 2]);

    expect(AuthenticatorData::parse($base . $extensions)->hasExtensions())->toBeTrue()
        ->and(fn () => AuthenticatorData::parse($base . $extensions . "\x00"))->toThrow(PasskeyRefused::class)
        ->and(fn () => AuthenticatorData::parse($base))->toThrow(PasskeyRefused::class)
        ->and(fn () => AuthenticatorData::parse($base . "\x01"))->toThrow(PasskeyRefused::class);
});

it('refuses data that is short, trailing or misshapen', function (string $bytes) {
    expect(fn () => AuthenticatorData::parse($bytes))->toThrow(PasskeyRefused::class);
})->with([
    'too short' => [str_repeat("\0", 36)],
    'trailing bytes' => [str_repeat("\0", 37) . "\x00"],
    'attested with nothing after' => [str_repeat("\0", 32) . "\x41" . pack('N', 0)],
    'a credential id shorter than 16' => [str_repeat("\0", 32) . "\x41" . pack('N', 0) . str_repeat("\0", 16) . pack('n', 4) . 'abcd' . "\xa0"],
    'a credential id past the data' => [str_repeat("\0", 32) . "\x41" . pack('N', 0) . str_repeat("\0", 16) . pack('n', 64) . str_repeat('a', 20)],
    'a credential key that is not a map' => [str_repeat("\0", 32) . "\x41" . pack('N', 0) . str_repeat("\0", 16) . pack('n', 16) . str_repeat('a', 16) . "\x01"],
]);

it('refuses a registration with bytes after its credential key', function () {
    $authenticator = SoftwareAuthenticator::es256();

    expect(fn () => AuthenticatorData::parse($authenticator->register('challenge', ['trailing' => "\x00"])['authData']))
        ->toThrow(PasskeyRefused::class);
});
