<?php

declare(strict_types=1);

namespace Tinvar\Tests\Render\Surface;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentMinter;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\FragmentTokenError;
use Tinvar\Render\RouteMinter;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;

#[Page('/search/{q}', view: 'search', name: 'surface.search'), NoAuthorization(reason: 'a test page')]
final class SearchPage {}

/**
 * Signs an arbitrary payload segment exactly as the envelope does.
 *
 * Every test here is about what decoding does AFTER the MAC verifies, so the
 * forgery has to carry a valid one: the version and purpose are inside it.
 */
function surfaceSigned(Signer $signer, string $purpose, string $body): string
{
    return 'v1.' . $body . '.' . Signer::encode($signer->sign('v1.' . $purpose . '.' . $body));
}

/** @return array<string, mixed> a claims map that would decode cleanly */
function surfaceClaims(Signer $signer): array
{
    return (new TokenClaims(
        route: 'racks.show',
        params: ['rack' => 'r-1'],
        panel: 'admin',
        tenant: 'acme',
        build: 'build-1',
        sessionHash: TokenClaims::hashSession($signer, 'session-abc'),
        expiresAt: time() + 600,
        issuedAt: time(),
    ))->toMap();
}

describe('minter lifetimes', function () {
    // decode() hard-rejects an expiry past DEFAULT_TTL, so a longer
    // configured TTL would mint tokens that fail as if forged.
    it('refuses a fragment token TTL outside what decoding accepts', function (int $ttl) {
        expect(fn () => new FragmentMinter(
            signer: new Signer('test-app-key', FragmentToken::PURPOSE),
            route: 'racks.show',
            params: [],
            panel: 'admin',
            tenant: 'acme',
            build: 'build-1',
            sessionId: 'session-abc',
            ttl: $ttl,
        ))->toThrow(\InvalidArgumentException::class, 'A fragment token TTL must be between 1 second and 3600');
    })->with([0, FragmentToken::DEFAULT_TTL + 1]);

    it('refuses a route token TTL outside what decoding accepts', function (int $ttl) {
        expect(fn () => new RouteMinter(
            signer: new Signer('test-app-key', RouteToken::PURPOSE),
            panel: 'admin',
            tenant: 'acme',
            build: 'build-1',
            sessionId: 'session-abc',
            ttl: $ttl,
        ))->toThrow(\InvalidArgumentException::class, 'A route token TTL must be between 1 second and 3600');
    })->with([0, RouteToken::DEFAULT_TTL + 1]);
});

// A path segment is percent-decoded to bytes, and a route whose loader takes
// any text took `/search/%FF`: its token's params could not be written as
// JSON, and the page was a 500 for asking. No token is a page that renders.
describe('a page whose params are not text', function () {
    it('gets no route token', function () {
        $routes = new RouteRegistry();
        $routes->register(SearchPage::class);
        $minter = new RouteMinter(
            signer: new Signer('test-app-key', RouteToken::PURPOSE),
            panel: '',
            tenant: '',
            build: 'build-1',
            sessionId: 'session-abc',
        );

        expect($minter->mint($routes->chainFor('surface.search', ['q' => "\xFF"])))->toBeNull()
            ->and($minter->mint($routes->chainFor('surface.search', ['q' => 'bolts'])))->toBeString();
    });
});

describe('a correctly signed envelope with a bad payload', function () {
    // The MAC proves this server signed the segment, not that the segment is
    // well formed: a signing bug or a key used elsewhere must still fail as a
    // TokenError, the one failure callers handle, and never as a warning.
    it('refuses a payload that is not base64url', function () {
        $signer = new Signer('test-app-key', RouteToken::PURPOSE);

        expect(fn () => RouteToken::decode(surfaceSigned($signer, RouteToken::PURPOSE, 'not+base64'), $signer))
            ->toThrow(TokenError::class, 'payload is not valid encoding');
    });

    it('refuses a payload that is not JSON', function () {
        $signer = new Signer('test-app-key', RouteToken::PURPOSE);
        $token = surfaceSigned($signer, RouteToken::PURPOSE, Signer::encode('{not json'));

        expect(fn () => RouteToken::decode($token, $signer))
            ->toThrow(TokenError::class, 'payload is not valid JSON');
    });

    it('refuses a payload that is JSON but not an object', function () {
        $signer = new Signer('test-app-key', RouteToken::PURPOSE);
        $token = surfaceSigned($signer, RouteToken::PURPOSE, Signer::encode('42'));

        expect(fn () => RouteToken::decode($token, $signer))
            ->toThrow(TokenError::class, 'payload is not an object');
    });
});

describe('claims read from a verified payload', function () {
    it('refuses a payload missing a string claim', function () {
        $signer = new Signer('test-app-key', RouteToken::PURPOSE);
        $claims = surfaceClaims($signer);
        unset($claims['r']);
        $token = surfaceSigned($signer, RouteToken::PURPOSE, Signer::encode(json_encode($claims)));

        expect(fn () => RouteToken::decode($token, $signer))
            ->toThrow(TokenError::class, "field 'r' is missing or not a string");
    });

    it('refuses route params given as a list rather than a map', function () {
        $signer = new Signer('test-app-key', RouteToken::PURPOSE);
        $claims = ['a' => ['r-1']] + surfaceClaims($signer);
        $token = surfaceSigned($signer, RouteToken::PURPOSE, Signer::encode(json_encode($claims)));

        expect(fn () => RouteToken::decode($token, $signer))
            ->toThrow(TokenError::class, "field 'a' is not an object");
    });

    it('refuses a fragment token that names no component', function () {
        $signer = new Signer('test-app-key', FragmentToken::PURPOSE);
        $token = surfaceSigned(
            $signer,
            FragmentToken::PURPOSE,
            Signer::encode(json_encode(surfaceClaims($signer) + ['p' => ['unit' => 'u-1']])),
        );

        expect(fn () => FragmentToken::decode($token, $signer))
            ->toThrow(FragmentTokenError::class, "field 'c' is missing or not a string");
    });
});

describe('the fragment manifest file', function () {
    // Valid JSON is not enough: a build that wrote a bare value is broken.
    it('refuses a manifest that is valid JSON but not an object', function () {
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, '"racks.show"');

        try {
            expect(fn () => FragmentManifest::fromFile($path))
                ->toThrow(\RuntimeException::class, 'is not an object');
        } finally {
            unlink($path);
        }
    });
});
