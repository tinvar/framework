<?php

declare(strict_types=1);

/**
 * Binary through the json-lines codec, which has to carry it as base64.
 *
 * Bytes are bytes: a chunk the renderer sends may be any of them, including
 * one PHP happens to treat as falsy.
 */

namespace Tinvar\Tests\Codec\JsonLinesBinary;

use Tinvar\Codec\CodecException;
use Tinvar\Codec\JsonLinesCodec;
use Tinvar\Codec\MessagePack;
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

// The renderer runs third-party npm code, and json_decode() builds whatever
// it is handed: twelve megabytes of `[],` took 146 MB, past the default
// memory limit -- a fatal error no catch can answer. The MessagePack decoder
// has refused such a frame since it was found there; this codec is still one
// a renderer can be started with.
it('refuses more elements than a frame may hold, before building them', function () {
    $codec = new JsonLinesCodec();
    $body = '{"t":"meta","x":[' . rtrim(str_repeat('[],', MessagePack::MAX_ELEMENTS + 1), ',') . ']}';
    $buffer = pack('N', \strlen($body)) . $body;
    $offset = 0;

    expect(fn () => $codec->decodeFrame($buffer, $offset))->toThrow(CodecException::class, 'more than');
});

it('counts no comma or bracket inside a string against the budget', function () {
    $codec = new JsonLinesCodec();
    $text = str_repeat('a, [b] {c}, \\"d\\" \\\\, ', MessagePack::MAX_ELEMENTS);
    $body = '{"t":"meta","mail":{"text":"' . $text . '"}}';
    $buffer = pack('N', \strlen($body)) . $body;
    $offset = 0;

    expect($codec->decodeFrame($buffer, $offset)['mail']['text'] ?? null)->toBe(json_decode('"' . $text . '"'));
});
