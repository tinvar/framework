<?php

declare(strict_types=1);

/**
 * The passkey verifier (WebAuthn Level 2, §7.1 and §7.2): a registration
 * and a sign-in from a software authenticator accepted, every check broken
 * one at a time and refused, and a frozen registration and sign-in read
 * byte for byte as a known answer.
 */

namespace Tinvar\Tests\Accounts\Passkeys\WebAuthn;

use Tinvar\Accounts\Passkeys\Base64Url;
use Tinvar\Accounts\Passkeys\CborBytes;
use Tinvar\Accounts\Passkeys\CoseKey;
use Tinvar\Accounts\Passkeys\PasskeyRefused;
use Tinvar\Accounts\Passkeys\RelyingParty;
use Tinvar\Accounts\Passkeys\WebAuthn;
use Tinvar\Tests\Accounts\Fixtures\SoftwareAuthenticator;

require_once __DIR__ . '/../Fixtures/SoftwareAuthenticator.php';

const CHALLENGE = 'a challenge the server issued, 32';

function verifier(): WebAuthn
{
    return new WebAuthn(new RelyingParty('app.example', ['https://app.example'], 'App'));
}

/** The reason $work is refused with, or null when it is not. */
function refusal(\Closure $work): ?string
{
    try {
        $work();
    } catch (PasskeyRefused $e) {
        expect($e->getMessage())->not->toBe('');

        return $e->reason;
    }

    return null;
}

function registers(SoftwareAuthenticator $authenticator, array $options = [], string $challenge = CHALLENGE, bool $uv = true): \Closure
{
    $response = $authenticator->register($challenge, $options);

    return static fn () => verifier()->verifyRegistration($response['clientDataJson'], $response['attestationObject'], CHALLENGE, $uv);
}

function asserts(SoftwareAuthenticator $authenticator, array $options = [], int $stored = 0, string $challenge = CHALLENGE, bool $uv = true): \Closure
{
    $response = $authenticator->assert($challenge, $options);

    return static fn () => verifier()->verifyAssertion($response['clientDataJson'], $response['authenticatorData'], $response['signature'], CHALLENGE, $authenticator->coseBytes(), $stored, $uv);
}

it('registers an ES256 and an RS256 credential under `none`', function (string $kind) {
    $authenticator = $kind === 'es256' ? SoftwareAuthenticator::es256() : SoftwareAuthenticator::rs256();
    $registered = registers($authenticator)();

    expect($registered->credentialId)->toBe($authenticator->credentialId)
        ->and($registered->publicKey)->toBe($authenticator->coseBytes())
        ->and($registered->algorithm)->toBe($authenticator->algorithm)
        ->and($registered->format)->toBe('none')
        ->and($registered->userVerified)->toBeTrue()
        ->and($registered->signCount)->toBe(0)
        ->and($registered->aaguid)->toBe(str_repeat("\xab", 16));
})->with(['es256', 'rs256']);

it('registers under a packed self attestation it verifies', function () {
    $authenticator = SoftwareAuthenticator::es256();
    $registered = registers($authenticator, ['fmt' => 'packed'])();

    expect($registered->format)->toBe('packed');
});

it('reads the backup flags of a synced passkey', function () {
    $registered = registers(SoftwareAuthenticator::es256(), ['flags' => 0x5D])();

    expect($registered->backupEligible)->toBeTrue()->and($registered->backedUp)->toBeTrue();
});

it('refuses a registration with any check broken', function (array $options, string $reason) {
    expect(refusal(registers(SoftwareAuthenticator::es256(), $options)))->toBe($reason);
})->with([
    'another origin' => [['origin' => 'https://evil.example'], 'origin'],
    'a subdomain the party does not name' => [['origin' => 'https://www.app.example'], 'origin'],
    'plain http' => [['origin' => 'http://app.example'], 'origin'],
    'a cross-origin frame' => [['crossOrigin' => true], 'origin'],
    'another relying party' => [['rpId' => 'evil.example'], 'rp_id'],
    'the sign-in type' => [['type' => 'webauthn.get'], 'type'],
    'no user present' => [['flags' => 0x44], 'user_verification'],
    'no user verified' => [['flags' => 0x41], 'user_verification'],
    'no attested credential' => [['flags' => 0x05], 'malformed'],
    'EdDSA' => [['cose' => [1 => 1, 3 => -8, -1 => 6, -2 => new CborBytes(str_repeat('x', 32))]], 'algorithm'],
    'an unread format' => [['fmt' => 'tpm', 'attStmt' => []], 'format'],
    'none with a statement' => [['attStmt' => ['alg' => -7]], 'malformed'],
    'packed with another key\'s signature' => [['fmt' => 'packed', 'attStmt' => ['alg' => -7, 'sig' => new CborBytes('nope')]], 'signature'],
    'packed in another algorithm' => [['fmt' => 'packed', 'attStmt' => ['alg' => -257, 'sig' => new CborBytes('nope')]], 'signature'],
    'packed in an unaccepted algorithm' => [['fmt' => 'packed', 'attStmt' => ['alg' => -8, 'sig' => new CborBytes('nope')]], 'algorithm'],
    'packed with no signature' => [['fmt' => 'packed', 'attStmt' => ['alg' => -7]], 'malformed'],
    'trailing bytes in its data' => [['trailing' => "\x00"], 'malformed'],
]);

it('refuses another challenge', function () {
    expect(refusal(registers(SoftwareAuthenticator::es256(), challenge: 'another challenge')))->toBe('challenge');
});

it('registers without user verification only where it was not asked for', function () {
    expect(refusal(registers(SoftwareAuthenticator::es256(), ['flags' => 0x41], uv: false)))->toBeNull();
});

it('refuses a packed self attestation whose signature covers other client data', function () {
    $authenticator = SoftwareAuthenticator::es256();
    $first = $authenticator->register(CHALLENGE, ['fmt' => 'packed']);
    $second = $authenticator->register(CHALLENGE, ['fmt' => 'packed', 'origin' => 'https://app.example']);

    // The attestation object of one with the client data of another: its
    // signature covers the hash of the first client data, not this one's.
    $tampered = str_replace('"crossOrigin":false', '"crossOrigin":false ', $second['clientDataJson']);
    expect(refusal(static fn () => verifier()->verifyRegistration($tampered, $first['attestationObject'], CHALLENGE)))->toBe('signature');
});

it('refuses an attestation object that is not one', function (string $object) {
    $client = SoftwareAuthenticator::es256()->register(CHALLENGE)['clientDataJson'];

    expect(refusal(static fn () => verifier()->verifyRegistration($client, $object, CHALLENGE)))->toBe('malformed');
})->with([
    'not CBOR' => ["\xff"],
    'a list' => [SoftwareAuthenticator::cbor([1, 2])],
    'authData as text' => [SoftwareAuthenticator::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => 'text'])],
    'no fmt' => [SoftwareAuthenticator::cbor(['attStmt' => [], 'authData' => new CborBytes('x')])],
    'trailing bytes' => [SoftwareAuthenticator::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes('x')]) . "\x00"],
]);

it('refuses client data that is not JSON of the right shape', function (string $client) {
    $object = SoftwareAuthenticator::es256()->register(CHALLENGE)['attestationObject'];

    expect(refusal(static fn () => verifier()->verifyRegistration($client, $object, CHALLENGE)))->toBe('malformed');
})->with([
    'not JSON' => ['{'],
    'a list' => ['[]'],
    'a numeric challenge' => ['{"type":"webauthn.create","challenge":1,"origin":"https://app.example"}'],
    'a string crossOrigin' => ['{"type":"webauthn.create","challenge":"x","origin":"https://app.example","crossOrigin":"false"}'],
    'no origin' => ['{"type":"webauthn.create","challenge":"x"}'],
]);

it('signs in with an ES256 and an RS256 credential, the counter moving on', function (string $kind) {
    $authenticator = $kind === 'es256' ? SoftwareAuthenticator::es256() : SoftwareAuthenticator::rs256();
    $first = asserts($authenticator, stored: 0)();
    $second = asserts($authenticator, stored: $first->signCount)();

    expect($first->signCount)->toBe(1)->and($second->signCount)->toBe(2)->and($second->userVerified)->toBeTrue();
})->with(['es256', 'rs256']);

it('signs in with a credential that keeps no counter', function () {
    $verified = asserts(SoftwareAuthenticator::es256(), ['signCount' => 0], stored: 0)();

    expect($verified->signCount)->toBe(0);
});

it('refuses a sign-in with any check broken', function (array $options, string $reason, int $stored = 0) {
    expect(refusal(asserts(SoftwareAuthenticator::es256(), $options, $stored)))->toBe($reason);
})->with([
    'another origin' => [['origin' => 'https://evil.example'], 'origin'],
    'a cross-origin frame' => [['crossOrigin' => true], 'origin'],
    'another relying party' => [['rpId' => 'evil.example'], 'rp_id'],
    'the registration type' => [['type' => 'webauthn.create'], 'type'],
    'no user present' => [['flags' => 0x04], 'user_verification'],
    'no user verified' => [['flags' => 0x01], 'user_verification'],
    'a counter gone back' => [['signCount' => 4], 'counter', 5],
    'a counter standing still' => [['signCount' => 5], 'counter', 5],
    'a counter dropped to zero' => [['signCount' => 0], 'counter', 5],
]);

it('refuses another challenge at sign-in', function () {
    expect(refusal(asserts(SoftwareAuthenticator::es256(), challenge: 'another challenge')))->toBe('challenge');
});

it('refuses a tampered signature, authenticator data or client data', function () {
    $authenticator = SoftwareAuthenticator::es256();
    $response = $authenticator->assert(CHALLENGE);
    $key = $authenticator->coseBytes();
    $verify = static fn (string $client, string $data, string $signature) => static fn () => verifier()->verifyAssertion($client, $data, $signature, CHALLENGE, $key, 0);

    $flipped = $response['authenticatorData'];
    $flipped[36] = \chr(\ord($flipped[36]) ^ 0x01);
    $signature = $response['signature'];
    $signature[\strlen($signature) - 1] = \chr(\ord($signature[\strlen($signature) - 1]) ^ 0x01);
    $client = str_replace('"crossOrigin":false', '"crossOrigin":false ', $response['clientDataJson']);

    expect(refusal($verify($response['clientDataJson'], $response['authenticatorData'], $response['signature'])))->toBeNull()
        ->and(refusal($verify($response['clientDataJson'], $flipped, $response['signature'])))->toBe('signature')
        ->and(refusal($verify($response['clientDataJson'], $response['authenticatorData'], $signature)))->toBe('signature')
        ->and(refusal($verify($client, $response['authenticatorData'], $response['signature'])))->toBe('signature')
        ->and(refusal($verify($response['clientDataJson'], $response['authenticatorData'], '')))->toBe('signature');
});

it('refuses a signature by another credential', function () {
    $response = SoftwareAuthenticator::es256()->assert(CHALLENGE);
    $other = SoftwareAuthenticator::es256()->coseBytes();

    expect(refusal(static fn () => verifier()->verifyAssertion($response['clientDataJson'], $response['authenticatorData'], $response['signature'], CHALLENGE, $other, 0)))->toBe('signature');
});

it('refuses an empty expected challenge rather than matching an empty one', function () {
    $response = SoftwareAuthenticator::es256()->register('');

    expect(refusal(static fn () => verifier()->verifyRegistration($response['clientDataJson'], $response['attestationObject'], '')))->toBe('challenge');
});

it('holds a relying party to a host name and origins on it', function (\Closure $make) {
    expect($make)->toThrow(\InvalidArgumentException::class);
})->with([
    'an address' => [fn () => new RelyingParty('127.0.0.1', ['https://127.0.0.1'], 'App')],
    'uppercase' => [fn () => new RelyingParty('App.example', ['https://app.example'], 'App')],
    'an origin on another host' => [fn () => new RelyingParty('app.example', ['https://evil.example'], 'App')],
    'a host that only ends the same' => [fn () => new RelyingParty('app.example', ['https://myapp.example'], 'App')],
    'plain http off localhost' => [fn () => new RelyingParty('app.example', ['http://app.example'], 'App')],
    'an origin with a path' => [fn () => new RelyingParty('app.example', ['https://app.example/'], 'App')],
    'no origins' => [fn () => new RelyingParty('app.example', [], 'App')],
    'no name' => [fn () => new RelyingParty('app.example', ['https://app.example'], ' ')],
]);

it('takes http on localhost, subdomains of the id, and one origin\'s host as its id', function () {
    $local = RelyingParty::forOrigin('http://localhost:6123', 'App');
    $wide = new RelyingParty('app.example', ['https://app.example', 'https://admin.app.example:8443'], 'App');

    expect($local->host())->toBe('localhost')
        ->and($local->allows('http://localhost:6123'))->toBeTrue()
        ->and($local->allows('http://localhost:6124'))->toBeFalse()
        ->and($wide->allows('https://admin.app.example:8443'))->toBeTrue()
        ->and($wide->allows('https://admin.app.example'))->toBeFalse();
});

/*
 * A known answer: an ES256 registration (fmt `none`) and a sign-in from it,
 * made once by the software authenticator and frozen here, for the relying
 * party `localhost` at http://localhost:6123. Read byte for byte, so a
 * change to how any part is parsed fails here whatever the fixture does.
 */
const KNOWN_CHALLENGE_HEX = 'a6a2262abb4dbb580c5146727e83906b2a5fb4a81a12aa7f7c12cb41b6cf969c';
const KNOWN_CLIENT_CREATE = 'eyJ0eXBlIjoid2ViYXV0aG4uY3JlYXRlIiwiY2hhbGxlbmdlIjoicHFJbUtydE51MWdNVVVaeWZvT1FheXBmdEtnYUVxcF9mQkxMUWJiUGxwdyIsIm9yaWdpbiI6Imh0dHA6Ly9sb2NhbGhvc3Q6NjEyMyIsImNyb3NzT3JpZ2luIjpmYWxzZX0';
const KNOWN_ATTESTATION = 'o2NmbXRkbm9uZWdhdHRTdG10oGhhdXRoRGF0YVikSZYN5YgOjGh0NBcPZHZgW4_krrmihjLHmVzzuoMdl2NFAAAAAKurq6urq6urq6urq6urq6sAIE3zVlYrFRTpkslfhp9naLPZ8QTRt61gC3IACQFPffQNpQECAyYgASFYIEE8CqspsFHF9IgrrWCxpRWS7ynkxlUdCsd-juJ8ezi0Ilgg8LuZWmuiP63v9ks2wIG6Jc9c5vRRSLCYCNi5xVl7UC4';
const KNOWN_CREDENTIAL_ID = 'TfNWVisVFOmSyV-Gn2dos9nxBNG3rWALcgAJAU999A0';
const KNOWN_PUBLIC_KEY = 'pQECAyYgASFYIEE8CqspsFHF9IgrrWCxpRWS7ynkxlUdCsd-juJ8ezi0Ilgg8LuZWmuiP63v9ks2wIG6Jc9c5vRRSLCYCNi5xVl7UC4';
const KNOWN_CLIENT_GET = 'eyJ0eXBlIjoid2ViYXV0aG4uZ2V0IiwiY2hhbGxlbmdlIjoicHFJbUtydE51MWdNVVVaeWZvT1FheXBmdEtnYUVxcF9mQkxMUWJiUGxwdyIsIm9yaWdpbiI6Imh0dHA6Ly9sb2NhbGhvc3Q6NjEyMyIsImNyb3NzT3JpZ2luIjpmYWxzZX0';
const KNOWN_AUTHENTICATOR_DATA = 'SZYN5YgOjGh0NBcPZHZgW4_krrmihjLHmVzzuoMdl2MFAAAAAQ';
const KNOWN_SIGNATURE = 'MEUCIF5F67AKIlMY0cQAOXs2d2rQZMqJFqK7mZSPxSga4lcZAiEAumkYU7ij2CBrXb_Yk0UThHgC04QUdnxnmo-QV5WJtes';

it('reads a frozen registration and sign-in byte for byte', function () {
    $verifier = new WebAuthn(RelyingParty::forOrigin('http://localhost:6123', 'App'));
    $challenge = (string) hex2bin(KNOWN_CHALLENGE_HEX);

    $registered = $verifier->verifyRegistration((string) Base64Url::decode(KNOWN_CLIENT_CREATE), (string) Base64Url::decode(KNOWN_ATTESTATION), $challenge);
    expect(Base64Url::encode($registered->credentialId))->toBe(KNOWN_CREDENTIAL_ID)
        ->and(Base64Url::encode($registered->publicKey))->toBe(KNOWN_PUBLIC_KEY)
        ->and($registered->algorithm)->toBe(CoseKey::ES256)
        ->and($registered->format)->toBe('none')
        ->and($registered->signCount)->toBe(0);

    $asserted = $verifier->verifyAssertion(
        (string) Base64Url::decode(KNOWN_CLIENT_GET),
        (string) Base64Url::decode(KNOWN_AUTHENTICATOR_DATA),
        (string) Base64Url::decode(KNOWN_SIGNATURE),
        $challenge,
        $registered->publicKey,
        0,
    );
    expect($asserted->signCount)->toBe(1)
        ->and(fn () => $verifier->verifyAssertion(
            (string) Base64Url::decode(KNOWN_CLIENT_GET),
            (string) Base64Url::decode(KNOWN_AUTHENTICATOR_DATA),
            (string) Base64Url::decode(KNOWN_SIGNATURE),
            $challenge,
            $registered->publicKey,
            1,
        ))->toThrow(PasskeyRefused::class);
});
