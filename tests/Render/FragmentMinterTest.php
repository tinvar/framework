<?php

declare(strict_types=1);

/**
 * ADR 0009 claims the renderer cannot choose a token's route, params, panel,
 * build, session or expiry. That claim was VACUOUS at the commit that made
 * it: nothing in the repository ever passed a mint closure, so the place
 * where those fields would be chosen did not exist. These tests are what makes
 * the claim a property of the framework rather than a convention each
 * application is trusted to follow.
 */

use Tinvar\Protocol\Frame;
use Tinvar\Render\FragmentMinter;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\FragmentTokenError;
use Tinvar\Support\Signer;

function minterSigner(): Signer
{
    return new Signer('test-app-key', FragmentToken::PURPOSE);
}

function minter(Signer $signer, array $overrides = []): FragmentMinter
{
    return new FragmentMinter(
        signer: $signer,
        route: $overrides['route'] ?? 'racks.show',
        params: $overrides['params'] ?? ['rack' => 'r-1'],
        panel: $overrides['panel'] ?? 'admin',
        tenant: $overrides['tenant'] ?? 'acme',
        build: $overrides['build'] ?? 'build-7',
        sessionId: $overrides['sessionId'] ?? 'session-abc',
    );
}

describe('what the renderer may not choose', function () {
    it('binds the route, params, panel and build from the request being served', function () {
        $signer = minterSigner();

        $token = FragmentToken::decode(minter($signer)->mint('UnitHistory', ['unit' => 'u-1']), $signer);

        expect($token->route)->toBe('racks.show')
            ->and($token->params)->toBe(['rack' => 'r-1'])
            ->and($token->panel)->toBe('admin')
            ->and($token->build)->toBe('build-7')
            ->and($token->component)->toBe('UnitHistory')
            ->and($token->props)->toBe(['unit' => 'u-1']);
    });

    // Props live under their own key, so a prop named like a binding field is
    // just a prop. Verified rather than assumed.
    it('is not influenced by props named after binding fields', function () {
        $signer = minterSigner();

        $token = FragmentToken::decode(
            minter($signer)->mint('X', ['r' => 'other.route', 's' => 'fake', 'e' => PHP_INT_MAX]),
            $signer,
        );

        expect($token->route)->toBe('racks.show')
            ->and($token->props)->toBe(['r' => 'other.route', 's' => 'fake', 'e' => PHP_INT_MAX]);
    });

    it('binds the session of the page being rendered', function () {
        $signer = minterSigner();

        $token = FragmentToken::decode(minter($signer)->mint('X', []), $signer);

        expect($token->belongsTo($signer, 'session-abc'))->toBeTrue()
            ->and($token->belongsTo($signer, 'another-session'))->toBeFalse();
    });

    it('gives every token the framework expiry', function () {
        $signer = minterSigner();
        $at = time();

        $token = FragmentToken::decode(minter($signer)->mint('X', []), $signer);

        expect($token->expiresAt - $at)->toBeLessThanOrEqual(FragmentToken::DEFAULT_TTL)
            ->and($token->expiresAt)->toBeGreaterThan($at);
    });

    // Minting a token bound to a constant hash would make every anonymous
    // visitor's tokens interchangeable.
    it('refuses to mint with no session', function () {
        expect(fn () => minter(minterSigner(), ['sessionId' => '']))
            ->toThrow(InvalidArgumentException::class, 'session is required');
    });
});

describe('what the renderer sends', function () {
    it('refuses a component that is not a component id', function (string $component) {
        expect(fn () => minter(minterSigner())->mint($component, []))
            ->toThrow(FragmentTokenError::class, 'component');
    })->with([
        'traversal' => ['../../etc/passwd'],
        'null byte' => ["A\0B"],
        'empty' => [''],
        'leading digit' => ['9Lives'],
        'too long' => [str_repeat('A', 200)],
    ]);

    // Encoded props and the token both have caps; without a byte bound one
    // render could hand PHP 64 x 16 MiB to encode, HMAC and base64, for
    // tokens far past what the endpoint would ever accept.
    it('refuses props larger than a token may carry', function () {
        $props = ['pad' => str_repeat('p', FragmentMinter::MAX_PROPS_BYTES)];

        expect(fn () => minter(minterSigner())->mint('X', $props))
            ->toThrow(FragmentTokenError::class, 'larger than');
    });

    // encode() used json_encode's default depth of 512 while decode() reads at
    // 32, so a renderer could mint tokens that would never verify.
    it('refuses props nested deeper than a token survives', function () {
        $deep = 'leaf';
        for ($i = 0; $i < 60; $i++) {
            $deep = ['next' => $deep];
        }

        expect(fn () => minter(minterSigner())->mint('X', ['deep' => $deep]))
            ->toThrow(FragmentTokenError::class, 'cannot be encoded');
    });

    // Invalid UTF-8 threw JsonException out of the signing path and became a
    // 500 driven entirely by the semi-trusted side.
    it('refuses props that are not valid UTF-8', function () {
        expect(fn () => minter(minterSigner())->mint('X', ['bad' => "\xB1\x31"]))
            ->toThrow(FragmentTokenError::class, 'cannot be encoded');
    });

    it('refuses more tokens than one render may mint', function () {
        $minter = minter(minterSigner());

        for ($i = 0; $i < Frame::MAX_MINTS_PER_RENDER; $i++) {
            $minter->mint('X', ['n' => $i]);
        }

        expect(fn () => $minter->mint('X', ['n' => 'one too many']))
            ->toThrow(FragmentTokenError::class, 'more fragment tokens');
    });

    it('mints a token the endpoint would accept', function () {
        $signer = minterSigner();

        $encoded = minter($signer)->mint('UnitHistory', ['unit' => 'u-1']);

        expect(\strlen($encoded))->toBeLessThan(4096, 'under FragmentEndpoint::MAX_TOKEN_BYTES')
            ->and(FragmentToken::decode($encoded, $signer)->component)->toBe('UnitHistory');
    });
});

/**
 * The seam the review found missing: FragmentSupport builds the minter, so an
 * application never chooses a token's binding fields itself.
 */
describe('FragmentSupport owns the minter', function () {
    it('binds the build it is configured with and the session it resolves', function () {
        $signer = minterSigner();
        $support = new Tinvar\Http\FragmentSupport(
            signer: $signer,
            builds: new Tinvar\Http\BuildRegistry('build-9'),
            render: static fn (): mixed => null,
            sessionId: static fn (Tinvar\Http\Request $r): string => 'session-from-request',
        );

        $token = FragmentToken::decode(
            $support->minterFor(Tinvar\Http\Request::get('/racks/r-1'), 'racks.show', ['rack' => 'r-1'])
                ->mint('UnitHistory', ['unit' => 'u-1']),
            $signer,
        );

        expect($token->build)->toBe('build-9', 'the current build, not one the renderer named')
            ->and($token->route)->toBe('racks.show')
            ->and($token->params)->toBe(['rack' => 'r-1'])
            ->and($token->belongsTo($signer, 'session-from-request'))->toBeTrue();
    });

    it('refuses to build a minter when the request has no session', function () {
        $support = new Tinvar\Http\FragmentSupport(
            signer: minterSigner(),
            builds: new Tinvar\Http\BuildRegistry('build-9'),
            render: static fn (): mixed => null,
            sessionId: static fn (Tinvar\Http\Request $r): string => '',
        );

        expect(fn () => $support->minterFor(Tinvar\Http\Request::get('/x'), 'racks.show', []))
            ->toThrow(InvalidArgumentException::class, 'session is required');
    });
});
