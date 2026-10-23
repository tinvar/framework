<?php

declare(strict_types=1);

/**
 * Binary through the json-lines codec, which has to carry it as base64.
 *
 * Bytes are bytes: a chunk the renderer sends may be any of them, including
 * one PHP happens to treat as falsy.
 */

namespace Tinvar\Tests\Codec\JsonLinesBinary;

use Tinvar\Codec\JsonLinesCodec;
use Tinvar\Protocol\Frame;

it('keeps a chunk that is the single byte "0"', function () {
    $codec = new JsonLinesCodec();
    $buffer = $codec->encodeFrame(Frame::chunk('0'));
    $offset = 0;

    expect($codec->decodeFrame($buffer, $offset))->toBe(['t' => 'chunk', 'b' => '0']);
});

it('keeps binary that is not UTF-8', function () {
    $codec = new JsonLinesCodec();
    $buffer = $codec->encodeFrame(Frame::chunk("\x89PNG\xff\x00"));
    $offset = 0;

    expect($codec->decodeFrame($buffer, $offset)['b'] ?? null)->toBe("\x89PNG\xff\x00");
});
