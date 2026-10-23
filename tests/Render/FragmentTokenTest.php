<?php

declare(strict_types=1);

use Tinvar\Render\FragmentToken;
use Tinvar\Render\FragmentTokenError;
use Tinvar\Support\Signer;

function signer(string $secret = 'test-app-key'): Signer
{
    return new Signer($secret, FragmentToken::PURPOSE);
}

function token(Signer $signer, array $overrides = []): FragmentToken
{
    return new FragmentToken(
        component: $overrides['component'] ?? 'UnitHistory',
        props: $overrides['props'] ?? ['unit' => 'u-1'],
        route: $overrides['route'] ?? 'racks.show',
        params: $overrides['params'] ?? ['rack' => 'r-1'],
        panel: $overrides['panel'] ?? 'admin',
        build: $overrides['build'] ?? 'build-1',
        sessionHash: $overrides['sessionHash'] ?? FragmentToken::hashSession($signer, 'session-abc'),
        expiresAt: $overrides['expiresAt'] ?? time() + 3600,
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
