<?php

declare(strict_types=1);

use Tinvar\Codec\Bin;
use Tinvar\Codec\CodecException;
use Tinvar\Codec\JsonLinesCodec;
use Tinvar\Codec\MessagePack;
use Tinvar\Codec\MessagePackCodec;

it('encodes each conformance vector to the expected wire bytes', function () {
    foreach (messagePackVectors() as $name => [$value, $hex]) {
        expect(bin2hex(MessagePack::encode($value)))->toBe($hex, "encoding {$name}");
    }
});

it('decodes each conformance vector back to the original value', function () {
    foreach (messagePackVectors() as $name => [$value, $hex]) {
        expect(MessagePack::decode(hex2bin($hex)))->toBe($value, "decoding {$name}");
    }
});

it('carries integers beyond 2^53 without a marker', function () {
    // tinvar-spec.md: "Integers beyond 2^53 encode natively, so big-integer
    // handling on this hop needs no marker."
    foreach ([9007199254740993, PHP_INT_MAX, PHP_INT_MIN, -9007199254740993] as $n) {
        expect(MessagePack::decode(MessagePack::encode($n)))->toBe($n);
    }
});

it('passes non-UTF-8 binary through as bin, untouched', function () {
    $chunk = "\x00\xff\xfe<div>\x80";
    $encoded = MessagePack::encode(new Bin($chunk));

    expect(bin2hex($encoded))->toStartWith('c409')   // bin8, 9 bytes
        ->and(MessagePack::decode($encoded))->toBe($chunk);
});

it('rejects objects it has no encoding for', function () {
    MessagePack::encode(new stdClass());
})->throws(CodecException::class);

it('reports truncation rather than returning a partial value', function () {
    MessagePack::decode(hex2bin('cdff'));   // uint16 header, one byte short
})->throws(CodecException::class);

describe('framing', function () {
    $codecs = fn () => ['msgpack' => new MessagePackCodec(), 'json-lines' => new JsonLinesCodec()];

    it('round-trips a frame through a 4-byte big-endian length prefix', function () use ($codecs) {
        foreach ($codecs() as $label => $codec) {
            $frame = ['t' => 'render', 'route' => 'racks/show', 'params' => ['rack' => '0192f3a1']];
            $bytes = $codec->encodeFrame($frame);

            expect(unpack('N', substr($bytes, 0, 4))[1])->toBe(strlen($bytes) - 4, "{$label} length prefix");

            $offset = 0;
            expect($codec->decodeFrame($bytes, $offset))->toBe($frame, "{$label} round-trip")
                ->and($offset)->toBe(strlen($bytes), "{$label} consumed exactly one frame");
        }
    });

    it('returns null and holds position when the buffer is short', function () use ($codecs) {
        foreach ($codecs() as $label => $codec) {
            $bytes = $codec->encodeFrame(['t' => 'done']);
            foreach ([0, 2, strlen($bytes) - 1] as $cut) {
                $offset = 0;
                expect($codec->decodeFrame(substr($bytes, 0, $cut), $offset))->toBeNull("{$label} at {$cut}")
                    ->and($offset)->toBe(0, "{$label} offset held at {$cut}");
            }
        }
    });

    it('decodes two frames from one buffer', function () use ($codecs) {
        foreach ($codecs() as $label => $codec) {
            $buffer = $codec->encodeFrame(['t' => 'chunk', 'n' => 1]) . $codec->encodeFrame(['t' => 'done']);
            $offset = 0;

            expect($codec->decodeFrame($buffer, $offset))->toBe(['t' => 'chunk', 'n' => 1], $label)
                ->and($codec->decodeFrame($buffer, $offset))->toBe(['t' => 'done'], $label)
                ->and($codec->decodeFrame($buffer, $offset))->toBeNull($label);
        }
    });

    it('refuses to emit a frame larger than the cap', function () {
        (new MessagePackCodec(maxFrameBytes: 64))->encodeFrame(['t' => 'chunk', 'b' => str_repeat('x', 128)]);
    })->throws(CodecException::class);

    it('base64s binary on the json-lines codec and restores it exactly', function () {
        $chunk = "\x00\xff\xfe<div>\x80";
        $codec = new JsonLinesCodec();
        $bytes = $codec->encodeFrame(['t' => 'chunk', 'b' => new Bin($chunk)]);

        $offset = 0;
        expect($codec->decodeFrame($bytes, $offset)['b'])->toBe($chunk);
    });
});
