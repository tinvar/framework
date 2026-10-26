<?php

declare(strict_types=1);

use Tinvar\Render\FragmentToken;
use Tinvar\Render\FragmentTokenError;
use Tinvar\Render\TokenClaims;
use Tinvar\Support\Signer;

function signer(string $secret = 'test-app-key'): Signer
{
    return new Signer($secret, FragmentToken::PURPOSE);
}

/**
 * Re-signs a tampered payload the way the envelope does.
 *
 * The MAC covers the version AND the purpose, not just the body, so a forge
 * that signs only the body fails on the signature before reaching whatever
 * it was meant to test.
 */
function resign(Signer $signer, array $payload, string $purpose = FragmentToken::PURPOSE): string
{
    $body = Signer::encode(json_encode($payload));

    return 'v1.' . $body . '.' . Signer::encode($signer->sign('v1.' . $purpose . '.' . $body));
}

function token(Signer $signer, array $overrides = []): FragmentToken
{
    return new FragmentToken(
        component: $overrides['component'] ?? 'UnitHistory',
        props: $overrides['props'] ?? ['unit' => 'u-1'],
        route: $overrides['route'] ?? 'racks.show',
        params: $overrides['params'] ?? ['rack' => 'r-1'],
        panel: $overrides['panel'] ?? 'admin',
        tenant: $overrides['tenant'] ?? 'acme',
        build: $overrides['build'] ?? 'build-1',
        sessionHash: $overrides['sessionHash'] ?? FragmentToken::hashSession($signer, 'session-abc'),
        expiresAt: $overrides['expiresAt'] ?? time() + 3600,
        issuedAt: $overrides['issuedAt'] ?? time(),
    );
}

describe('the signer', function () {
    // "The signing key is derived from APP_KEY for this one purpose."
    it('derives a different key per purpose', function () {
        $a = new Signer('secret', 'fragment-token');
        $b = new Signer('secret', 'upload-ticket');

        expect($a->sign('payload'))->not->toBe($b->sign('payload'))
            ->and($b->verify('payload', $a->sign('payload')))
            ->toBeFalse('a value signed for one purpose must not verify for another');
    });

    // A signer with no key produces stable, forgeable MACs that look real.
    it('refuses an empty secret', function () {
        expect(fn () => new Signer('', 'fragment-token'))
            ->toThrow(InvalidArgumentException::class, 'APP_KEY');
    });

    it('refuses an empty purpose', function () {
        expect(fn () => new Signer('secret', ''))
            ->toThrow(InvalidArgumentException::class, 'purpose');
    });

    it('round-trips url-safe encoding', function () {
        $raw = random_bytes(64);

        expect(Signer::encode($raw))->toMatch('/^[A-Za-z0-9_-]+$/')
            ->and(Signer::decode(Signer::encode($raw)))->toBe($raw);
    });

    it('refuses encoding that is not url-safe base64', function (string $bad) {
        expect(Signer::decode($bad))->toBeNull();
    })->with(['', 'has spaces', 'has+plus', 'has/slash', 'has=pad', '../../etc']);
});

describe('fragment tokens', function () {
    it('round-trips every field', function () {
        $signer = signer();
        $original = token($signer);

        $decoded = FragmentToken::decode($original->encode($signer), $signer);

        expect($decoded->component)->toBe('UnitHistory')
            ->and($decoded->props)->toBe(['unit' => 'u-1'])
            ->and($decoded->route)->toBe('racks.show')
            ->and($decoded->params)->toBe(['rack' => 'r-1'])
            ->and($decoded->panel)->toBe('admin')
            ->and($decoded->build)->toBe('build-1')
            ->and($decoded->expiresAt)->toBe($original->expiresAt);
    });

    it('travels safely in a path', function () {
        $signer = signer();

        expect(token($signer)->encode($signer))->toMatch('/^v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/');
    });

    // The whole point of one token: props are not a separate parameter the
    // caller can change.
    it('refuses tampered props', function () {
        $signer = signer();
        $encoded = token($signer)->encode($signer);

        [$version, $payload, $mac] = explode('.', $encoded);
        $json = json_decode(Signer::decode($payload), true);
        $json['p'] = ['unit' => 'someone-elses-unit'];
        $tampered = $version . '.' . Signer::encode(json_encode($json)) . '.' . $mac;

        expect(fn () => FragmentToken::decode($tampered, $signer))
            ->toThrow(FragmentTokenError::class);
    });

    it('refuses a token signed with another key', function () {
        $encoded = token(signer('their-key'))->encode(signer('their-key'));

        expect(fn () => FragmentToken::decode($encoded, signer('our-key')))
            ->toThrow(FragmentTokenError::class);
    });

    // The version is inside the signed string, so a token cannot be replayed
    // under a later version whose fields mean something else.
    it('refuses a token whose version was swapped', function () {
        $signer = signer();
        $encoded = token($signer)->encode($signer);
        [, $payload, $mac] = explode('.', $encoded);

        expect(fn () => FragmentToken::decode('v2.' . $payload . '.' . $mac, $signer))
            ->toThrow(FragmentTokenError::class);
    });

    it('refuses a malformed token', function (string $bad) {
        expect(fn () => FragmentToken::decode($bad, signer()))
            ->toThrow(FragmentTokenError::class);
    })->with(['', 'nonsense', 'v1.only-two-parts', 'v1.a.b.c', 'v1..', '../../../etc/passwd']);

    it('refuses an expired token', function () {
        $signer = signer();
        $encoded = token($signer, ['expiresAt' => time() - 1])->encode($signer);

        expect(fn () => FragmentToken::decode($encoded, $signer))
            ->toThrow(FragmentTokenError::class, 'expired');
    });

    it('accepts one that has not expired yet', function () {
        $signer = signer();
        $encoded = token($signer, ['expiresAt' => time() + 10])->encode($signer);

        expect(FragmentToken::decode($encoded, $signer)->component)->toBe('UnitHistory');
    });
});

describe('the session binding', function () {
    // The token is readable in page source, so it must not carry a usable
    // session id -- that would be a session-fixation primitive in the DOM.
    it('stores a hash, not the session id', function () {
        $signer = signer();
        $encoded = token($signer)->encode($signer);

        expect(Signer::decode(explode('.', $encoded)[1]))->not->toContain('session-abc');
    });

    it('recognises its own session', function () {
        $signer = signer();

        expect(token($signer)->belongsTo($signer, 'session-abc'))->toBeTrue();
    });

    // "PHP rejects a token from another session."
    it('rejects another session', function () {
        $signer = signer();

        expect(token($signer)->belongsTo($signer, 'session-xyz'))->toBeFalse();
    });

    // Keyed, so the hash cannot be precomputed from a session id obtained
    // some other way.
    it('is keyed, so the same session hashes differently per key', function () {
        expect(FragmentToken::hashSession(signer('key-a'), 'session-abc'))
            ->not->toBe(FragmentToken::hashSession(signer('key-b'), 'session-abc'));
    });
});

describe('refreshing', function () {
    // "each fragment response returns a refreshed token, so refresh
    // boundaries outlive the first one."
    it('extends the expiry and keeps everything else', function () {
        $signer = signer();
        $now = time();
        $original = token($signer, ['expiresAt' => $now + 10]);

        $refreshed = $original->refreshed(now: $now);

        expect($refreshed->expiresAt)->toBe($now + FragmentToken::DEFAULT_TTL)
            ->and($refreshed->component)->toBe($original->component)
            ->and($refreshed->props)->toBe($original->props)
            ->and($refreshed->route)->toBe($original->route)
            ->and($refreshed->params)->toBe($original->params)
            ->and($refreshed->panel)->toBe($original->panel)
            ->and($refreshed->build)->toBe($original->build)
            ->and($refreshed->sessionHash)->toBe($original->sessionHash);
    });

    // A refreshed token must not extend a session binding onto a new session.
    it('still belongs to the original session only', function () {
        $signer = signer();

        expect(token($signer)->refreshed()->belongsTo($signer, 'session-xyz'))->toBeFalse();
    });
});

// Hardening from the fragment security review. Each was reproduced first.
describe('malleability and bounds', function () {
    // base64_decode skips whitespace even in strict mode, and '$' matches
    // before a trailing newline -- so one logical value had many accepted
    // spellings, and anything keyed on the string could be evaded.
    it('refuses encoding with trailing whitespace', function () {
        expect(Signer::decode("AAAA\n"))->toBeNull()
            ->and(Signer::decode('AAAA '))->toBeNull();
    });

    it('refuses a token with a trailing newline', function () {
        $signer = signer();
        $encoded = token($signer)->encode($signer);

        expect(fn () => FragmentToken::decode($encoded . "\n", $signer))
            ->toThrow(FragmentTokenError::class);
    });

    // base64url leaves slack in the final character, so several distinct
    // strings decode to one MAC. Accepting all of them is not a forgery, but
    // it defeats any replay cache or audit log keyed on the token string.
    it('refuses a non-canonical MAC encoding', function () {
        $signer = signer();
        [$version, $payload, $mac] = explode('.', token($signer)->encode($signer));

        // Flip the final character to another that decodes to the same bytes.
        // Decoded with plain base64, not Signer::decode(), which now refuses
        // the variant itself and so cannot say it names the same bytes.
        $raw = static fn (string $encoded): string|false => base64_decode(strtr($encoded, '-_', '+/'), true);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $variant = null;
        foreach (str_split($alphabet) as $character) {
            $candidate = substr($mac, 0, -1) . $character;
            if ($candidate !== $mac && $raw($candidate) === $raw($mac)) {
                $variant = $candidate;
                break;
            }
        }

        expect($variant)->not->toBeNull('a non-canonical spelling exists to test with')
            ->and(fn () => FragmentToken::decode("{$version}.{$payload}.{$variant}", $signer))
            ->toThrow(FragmentTokenError::class);
    });

    // Only the mint side applied the TTL, so a token claiming PHP_INT_MAX was
    // honoured forever.
    it('refuses an expiry further out than a token may be issued for', function () {
        $signer = signer();
        $encoded = token($signer, ['expiresAt' => PHP_INT_MAX])->encode($signer);

        expect(fn () => FragmentToken::decode($encoded, $signer))
            ->toThrow(FragmentTokenError::class, 'further out');
    });

    // Minted on a server whose clock is a few seconds ahead of the one that
    // reads it -- a boundary loaded at once, a live trade 50 ms after the
    // page -- a fresh token's expiry was further out than any is issued for,
    // and refused until the gap had passed. A minute is allowed for; a token
    // past it is still refused.
    it('allows for a minute between the clocks of the server that minted it and the one that reads it', function () {
        $signer = signer();
        $now = 1_800_000_000;
        $ahead = static fn (int $by): string => token($signer, [
            'issuedAt' => $now + $by,
            'expiresAt' => $now + $by + FragmentToken::DEFAULT_TTL,
        ])->encode($signer);

        expect(FragmentToken::decode($ahead(5), $signer, $now)->component)->toBe('UnitHistory')
            ->and(FragmentToken::decode($ahead(TokenClaims::CLOCK_SKEW), $signer, $now)->component)->toBe('UnitHistory')
            ->and(fn () => FragmentToken::decode($ahead(TokenClaims::CLOCK_SKEW + 1), $signer, $now))
            ->toThrow(FragmentTokenError::class, 'further out');
    });

    it('refuses params that are not scalars', function () {
        $signer = signer();
        $original = token($signer);
        [$version, $payload, ] = explode('.', $original->encode($signer));

        $json = json_decode(Signer::decode($payload), true);
        $json['a'] = ['rack' => ['nested' => true]];
        expect(fn () => FragmentToken::decode(resign($signer, $json), $signer))->toThrow(FragmentTokenError::class, 'not a scalar');
    });

    // A JSON list passes is_array() and then breaks the array<string, mixed>
    // contract everything downstream was promised.
    it('refuses props that are a list', function () {
        $signer = signer();
        [$version, $payload, ] = explode('.', token($signer)->encode($signer));

        $json = json_decode(Signer::decode($payload), true);
        $json['p'] = ['a', 'b'];
        expect(fn () => FragmentToken::decode(resign($signer, $json), $signer))->toThrow(FragmentTokenError::class);
    });
});

// From the Phase 2 review. Each was reproduced before being fixed.
describe('the absolute lifetime', function () {
    /**
     * issuedAt was a defaulted constructor parameter and the ceiling check
     * skipped it when zero -- so the one control the comment calls
     * load-bearing could be switched off by leaving an argument out. A
     * probe built a token that way, refreshed it three times, and it
     * verified ten years later.
     */
    it('refuses a token that carries no issue time', function () {
        $signer = signer();
        $encoded = token($signer, ['issuedAt' => 0])->encode($signer);

        expect(fn () => FragmentToken::decode($encoded, $signer))
            ->toThrow(FragmentTokenError::class, 'no issue time');
    });

    // Exactly at the deadline, too: refreshed() already treats it as spent,
    // and a token decode accepted then could not be refreshed.
    it('refuses one older than a token may live across refreshes', function (int $pastDeadline) {
        $signer = signer();
        $encoded = token($signer, ['issuedAt' => time() - FragmentToken::MAX_LIFETIME - $pastDeadline])
            ->encode($signer);

        expect(fn () => FragmentToken::decode($encoded, $signer))
            ->toThrow(FragmentTokenError::class, 'older than');
    })->with(['at the deadline' => 0, 'past it' => 1]);

    // Refreshing extends the expiry and must NOT extend the lifetime.
    it('does not let refreshing push back the deadline', function () {
        $signer = signer();
        $issued = time() - FragmentToken::MAX_LIFETIME + 10;

        $refreshed = token($signer, ['issuedAt' => $issued])->refreshed()->refreshed();

        expect($refreshed->issuedAt)->toBe($issued);
    });
});

/**
 * "Client-held data is bound to (user, tenant) ... any mismatch between
 * stored and current user or tenant also purges it." The panel was defended
 * carefully and the tenant was not carried at all.
 */
it('carries the tenant it was minted for', function () {
    $signer = signer();

    expect(FragmentToken::decode(token($signer, ['tenant' => 'acme'])->encode($signer), $signer)->tenant)
        ->toBe('acme');
});

it('keeps the tenant across a refresh', function () {
    $signer = signer();

    expect(token($signer, ['tenant' => 'acme'])->refreshed()->tenant)->toBe('acme');
});

/**
 * refreshed() kept issuedAt -- correctly, since refreshing must not extend
 * the absolute lifetime -- and clamped nothing, so a token issued just under
 * the ceiling came back with a nominal hour of life and was undecodable
 * within seconds, failing indistinguishably from a forgery. This is a LIVE
 * path: every fragment response returns a refreshed token.
 */
describe('refreshing near the deadline', function () {
    it('clamps the new expiry to the absolute deadline', function () {
        $signer = signer();
        $now = time();
        $issued = $now - FragmentToken::MAX_LIFETIME + 30;

        $refreshed = token($signer, ['issuedAt' => $issued])->refreshed(now: $now);

        expect($refreshed)->not->toBeNull()
            ->and($refreshed->expiresAt)->toBe($issued + FragmentToken::MAX_LIFETIME)
            ->and($refreshed->expiresAt - $now)->toBeLessThan(FragmentToken::DEFAULT_TTL);
    });

    // A clamped token must still decode, which the unclamped one did not.
    it('produces a token that still verifies', function () {
        $signer = signer();
        $now = time();
        $issued = $now - FragmentToken::MAX_LIFETIME + 30;

        $encoded = token($signer, ['issuedAt' => $issued])->refreshed(now: $now)->encode($signer);

        expect(FragmentToken::decode($encoded, $signer, now: $now + 10)->component)
            ->toBe('UnitHistory');
    });

    // Null says "re-bootstrap", which the caller can act on; a dead token
    // says nothing a forgery would not.
    it('refuses to refresh once the lifetime is spent', function () {
        $signer = signer();
        $now = time();

        expect(token($signer, ['issuedAt' => $now - FragmentToken::MAX_LIFETIME - 1])->refreshed(now: $now))
            ->toBeNull();
    });
});
