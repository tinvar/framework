<?php

declare(strict_types=1);

/**
 * TOTP against RFC 6238's own test vectors (Appendix B, SHA-1), base32
 * against RFC 4648's, and the rules verify() adds: a window of one step,
 * and no step used twice.
 */

namespace Tinvar\Tests\Accounts\Totp;

use Tinvar\Accounts\SessionCookie;
use Tinvar\Accounts\TwoFactor\Base32;
use Tinvar\Accounts\TwoFactor\Totp;

const RFC_SECRET = '12345678901234567890';

it('gives RFC 6238\'s eight-digit SHA-1 codes at its times', function (int $time, string $code) {
    expect(Totp::at(RFC_SECRET, $time, 8))->toBe($code)
        ->and(Totp::at(RFC_SECRET, $time))->toBe(substr($code, 2));
})->with([
    [59, '94287082'],
    [1111111109, '07081804'],
    [1111111111, '14050471'],
    [1234567890, '89005924'],
    [2000000000, '69279037'],
    [20000000000, '65353130'],
]);

it('takes a code one step either side of now, and no further', function () {
    $now = 1_700_000_000;
    foreach ([-1, 0, 1] as $off) {
        expect(Totp::verify(RFC_SECRET, Totp::at(RFC_SECRET, $now + $off * Totp::PERIOD), $now))->toBe(intdiv($now, Totp::PERIOD) + $off);
    }
    expect(Totp::verify(RFC_SECRET, Totp::at(RFC_SECRET, $now + 2 * Totp::PERIOD), $now))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, Totp::at(RFC_SECRET, $now - 2 * Totp::PERIOD), $now))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, '12345', $now))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, 'abcdef', $now))->toBeNull();
});

it('refuses a code for a step already used, or one before it', function () {
    $now = 1_700_000_000;
    $step = intdiv($now, Totp::PERIOD);
    $code = Totp::at(RFC_SECRET, $now);

    expect(Totp::verify(RFC_SECRET, $code, $now, $step - 1))->toBe($step)
        ->and(Totp::verify(RFC_SECRET, $code, $now, $step))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, Totp::at(RFC_SECRET, $now - Totp::PERIOD), $now, $step))->toBeNull();
});

it('encodes and decodes RFC 4648\'s base32 vectors, forgiving how a person copies one', function (string $bytes, string $text) {
    expect(Base32::encode($bytes))->toBe($text)
        ->and(Base32::decode($text))->toBe($bytes)
        ->and(Base32::decode(strtolower(chunk_split($text, 4, ' '))))->toBe($bytes);
})->with([
    ['f', 'MY'],
    ['fo', 'MZXQ'],
    ['foo', 'MZXW6'],
    ['foob', 'MZXW6YQ'],
    ['fooba', 'MZXW6YTB'],
    ['foobar', 'MZXW6YTBOI'],
]);

it('refuses what is not base32', function () {
    expect(Base32::decode(''))->toBeNull()
        ->and(Base32::decode('MZXW1'))->toBeNull()
        ->and(Base32::decode('MZ!W'))->toBeNull()
        // Leftover bits that are not zero: a second spelling of 'f'.
        ->and(Base32::decode('MZ'))->toBeNull();
});

it('writes an otpauth URI an authenticator app reads', function () {
    expect(Totp::uri(RFC_SECRET, 'My app', 'ada@example.com'))
        ->toBe('otpauth://totp/My%20app:ada%40example.com?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=My%20app&algorithm=SHA1&digits=6&period=30');
});

it('signs a session cookie under one of two purposes, and refuses one altered, expired or of another key', function () {
    $now = 1_700_000_000;
    $cookie = new SessionCookie('session-cookie-test-key-of-32-bytes!!', clock: static fn (): int => $now);
    $out = $cookie->issue();
    $in = $cookie->issue(signedIn: true);

    expect($cookie->id($out))->toMatch('/^[0-9a-f]{32}\z/')
        ->and($cookie->signedIn($out))->toBeFalse()
        ->and($cookie->signedIn($in))->toBeTrue()
        ->and($cookie->id(substr($out, 0, -1) . (str_ends_with($out, 'A') ? 'B' : 'A')))->toBeNull()
        ->and(new SessionCookie('another-key-entirely-of-32-bytes-long!!')->id($out))->toBeNull()
        ->and(new SessionCookie('session-cookie-test-key-of-32-bytes!!', clock: static fn (): int => $now + SessionCookie::LIFETIME)->id($out))->toBeNull()
        ->and(static fn () => new SessionCookie('k', 'app_session'))->toThrow(\InvalidArgumentException::class, '__Host-');
});
