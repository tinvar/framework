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
