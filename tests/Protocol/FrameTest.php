<?php

declare(strict_types=1);

use Tinvar\Codec\Bin;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;

it('builds a render frame carrying the per-route query allowlist and CSP nonce', function () {
    $frame = Frame::render(
        routeId: 'racks/show',
        params: ['rack' => '0192f3a1'],
        loaderData: ['rack' => ['name' => 'R-14']],
        requestId: 'req-1',
        cspNonce: 'kX9a',
        allowedQueries: ['racks.stats', 'racks.unitLoad'],
    );

    expect($frame['t'])->toBe(Frame::RENDER)
        ->and($frame['allow'])->toBe(['racks.stats', 'racks.unitLoad'])
        ->and($frame['nonce'])->toBe('kX9a');
});

it('carries the page metadata for a document render, and none otherwise', function () {
    $args = [
        'routeId' => 'racks/show',
        'params' => [],
        'loaderData' => [],
        'requestId' => 'req-1',
        'cspNonce' => 'n',
        'allowedQueries' => [],
    ];

    $with = Frame::render(...$args, page: new Tinvar\Protocol\PageMetadata('/racks/r-1?tab=2', 'b3', 'v1.tok', 'racks/show'));

    expect($with['page'])->toBe(['url' => '/racks/r-1?tab=2', 'version' => 'b3', 'routeToken' => 'v1.tok', 'view' => 'racks/show'])
        ->and(Frame::render(...$args))->not->toHaveKey('page');
});

// ADR 0015: a partial reload renders the segments it recomputed, alone.
it('names the segments a partial render renders, and none for a whole page', function () {
    $args = [
        'routeId' => 'racks/show',
        'params' => [],
        'loaderData' => ['page' => ['rack' => 'r-1'], 'layouts' => []],
        'requestId' => 'req-1',
        'cspNonce' => 'n',
        'allowedQueries' => [],
    ];

    expect(Frame::render(...$args, segments: ['page'])['segments'])->toBe(['page'])
        ->and(Frame::render(...$args, segments: [])['segments'])->toBe([])
        ->and(Frame::render(...$args))->not->toHaveKey('segments');
});

// The hidden field a form posts before JavaScript loads, and what such a
// post left for the page it was sent back to. A render with neither sends
// the frame it always sent.
it('carries the CSRF token and the flash for the page\'s forms only when given them', function () {
    $args = [
        'routeId' => 'racks/show',
        'params' => [],
        'loaderData' => [],
        'requestId' => 'req-1',
        'cspNonce' => 'n',
        'allowedQueries' => [],
    ];

    $with = Frame::render(...$args, csrfToken: 'tok', flash: ['form' => ['action' => 'a', 'errors' => []]]);

    expect($with['csrf'])->toBe('tok')
        ->and($with['flash'])->toBe(['form' => ['action' => 'a', 'errors' => []]])
        ->and(Frame::render(...$args))->not->toHaveKeys(['csrf', 'flash']);
});

it('wraps chunk bytes as binary so RSC output is not treated as text', function () {
    expect(Frame::chunk("\xff<div>")['b'])->toBeInstanceOf(Bin::class);
});

describe('frames are one-way', function () {
    // tinvar-spec.md, "Renderer hardening": the renderer sends chunks, query
    // calls, head tags and done/error -- "It cannot set flash data or anything
    // else that PHP persists."

    it('accepts every frame type the renderer is allowed to originate', function () {
        foreach (Frame::RENDERER_TO_PHP as $type) {
            expect(Frame::assertFromRenderer(['t' => $type]))->toBe($type);
        }
    });

    it('refuses a renderer frame that belongs to the PHP side', function (string $type) {
        expect(fn () => Frame::assertFromRenderer(['t' => $type]))
            ->toThrow(ProtocolException::class, "may not send a '{$type}' frame");
    })->with(Frame::PHP_TO_RENDERER);

    it('refuses an invented frame type', function () {
        Frame::assertFromRenderer(['t' => 'flash']);
    })->throws(ProtocolException::class);

    it('refuses a frame with a missing or non-string type', function (mixed $type) {
        expect(fn () => Frame::assertFromRenderer(['t' => $type]))->toThrow(ProtocolException::class);
    })->with([[null], [42], [['render']], [true]]);
});

describe('query batches are shape-checked before reaching the registry', function () {
    it('reads a well-formed batch', function () {
        $calls = Frame::readQueryBatch(['t' => Frame::QUERY, 'q' => [
            ['id' => 0, 'name' => 'racks.unitLoad', 'args' => ['unit' => 'u1']],
            ['id' => 1, 'name' => 'racks.stats', 'args' => []],
        ]]);

        expect($calls)->toHaveCount(2)
            ->and($calls[0]['name'])->toBe('racks.unitLoad')
            ->and($calls[1]['args'])->toBe([]);
    });

    it('defaults missing args to an empty map', function () {
        expect(Frame::readQueryBatch(['q' => [['id' => 0, 'name' => 'q']]])[0]['args'])->toBe([]);
    });

    it('refuses a malformed batch', function (mixed $q) {
        expect(fn () => Frame::readQueryBatch(['q' => $q]))->toThrow(ProtocolException::class);
    })->with([
        'not a list' => [['a' => 1]],
        'missing' => [null],
        'call not a map' => [['nope']],
        'non-int id' => [[['id' => 'x', 'name' => 'q']]],
        'missing name' => [[['id' => 0]]],
        'empty name' => [[['id' => 0, 'name' => '']]],
        'args not a map' => [[['id' => 0, 'name' => 'q', 'args' => 'x']]],
    ]);
});

/**
 * Minting is the one thing the renderer can ask PHP to do with the signing
 * key, and the renderer is the semi-trusted tier -- it "runs third-party npm
 * code". What it may influence is therefore bounded deliberately.
 */
describe('the mint exchange', function () {
    it('lets the renderer ask and PHP answer', function () {
        expect(Frame::RENDERER_TO_PHP)->toContain(Frame::MINT)
            ->and(Frame::PHP_TO_RENDERER)->toContain(Frame::MINTED)
            ->and(Frame::RENDERER_TO_PHP)->not->toContain(Frame::MINTED)
            ->and(Frame::PHP_TO_RENDERER)->not->toContain(Frame::MINT);
    });

    it('reads the component and props a boundary declared', function () {
        $requests = Frame::readMintBatch([
            't' => Frame::MINT,
            'm' => [['id' => 0, 'component' => 'UnitHistory', 'props' => ['unit' => 'u-1']]],
        ]);

        expect($requests)->toBe([['id' => 0, 'component' => 'UnitHistory', 'props' => ['unit' => 'u-1']]]);
    });

    // The cap bounds what a compromised renderer can extract from the key.
    it('refuses more requests than one render may make', function () {
        $many = array_map(
            static fn (int $i): array => ['id' => $i, 'component' => 'X', 'props' => []],
            range(0, Frame::MAX_MINTS_PER_RENDER),
        );

        expect(fn () => Frame::readMintBatch(['t' => Frame::MINT, 'm' => $many]))
            ->toThrow(ProtocolException::class, 'more than');
    });

    it('refuses a malformed request', function (array $frame) {
        expect(fn () => Frame::readMintBatch($frame))->toThrow(ProtocolException::class);
    })->with([
        'not a list' => [['t' => 'mint', 'm' => ['a' => 1]]],
        'missing m' => [['t' => 'mint']],
        'request not a map' => [['t' => 'mint', 'm' => ['nope']]],
        'no component' => [['t' => 'mint', 'm' => [['id' => 0, 'props' => []]]]],
        'empty component' => [['t' => 'mint', 'm' => [['id' => 0, 'component' => '', 'props' => []]]]],
        'non-integer id' => [['t' => 'mint', 'm' => [['id' => 'x', 'component' => 'C', 'props' => []]]]],
        'props as a list' => [['t' => 'mint', 'm' => [['id' => 0, 'component' => 'C', 'props' => ['a']]]]],
    ]);

    /**
     * Strictly an integer, as readQueryBatch already requires for the
     * identical field. Accepting numeric strings and casting made "1", "01"
     * and "0001" one id: each was signed and charged against the cap, one
     * token came back, and the other boundaries waited forever.
     */
    it('refuses a numeric-string id', function () {
        expect(fn () => Frame::readMintBatch([
            't' => Frame::MINT,
            'm' => [['id' => '01', 'component' => 'C', 'props' => []]],
        ]))->toThrow(ProtocolException::class, 'non-negative integer');
    });

    it('refuses a negative id', function () {
        expect(fn () => Frame::readMintBatch([
            't' => Frame::MINT,
            'm' => [['id' => -1, 'component' => 'C', 'props' => []]],
        ]))->toThrow(ProtocolException::class, 'non-negative integer');
    });

    it('refuses duplicate ids in one batch', function () {
        expect(fn () => Frame::readMintBatch([
            't' => Frame::MINT,
            'm' => [
                ['id' => 1, 'component' => 'C', 'props' => []],
                ['id' => 1, 'component' => 'D', 'props' => []],
            ],
        ]))->toThrow(ProtocolException::class, 'duplicate id');
    });

    it('pairs each token with the request that asked for it', function () {
        expect(Frame::minted([3 => 'tok-c', 1 => 'tok-a']))
            ->toBe(['t' => Frame::MINTED, 'm' => [
                ['id' => 3, 'token' => 'tok-c'],
                ['id' => 1, 'token' => 'tok-a'],
            ]]);
    });
});
