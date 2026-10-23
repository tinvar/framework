<?php

declare(strict_types=1);

/**
 * Every MessagePack size class the encoder writes and the decoder reads, at
 * its boundaries, and every way a frame can be malformed. The conformance
 * vectors in tests/Pest.php pin the common cases to the renderer's bytes;
 * these pin the rest.
 */

use Tinvar\Codec\Bin;
use Tinvar\Codec\CodecException;
use Tinvar\Codec\JsonLinesCodec;
use Tinvar\Codec\MessagePack;
use Tinvar\Codec\MessagePackCodec;

function roundTrip(mixed $value): mixed
{
    $offset = 0;
    $bytes = MessagePack::encode($value);
    $decoded = MessagePack::decode($bytes, $offset);
    expect($offset)->toBe(strlen($bytes));

    return $decoded;
}

describe('size classes', function () {
    it('round-trips binary in each length class', function (int $length, string $marker) {
        $bytes = str_repeat("\xff", $length);

        expect(MessagePack::encode(new Bin($bytes))[0])->toBe($marker)
            ->and(roundTrip(new Bin($bytes)))->toBe($bytes);
    })->with([
        'bin 8' => [255, "\xc4"],
        'bin 16' => [256, "\xc5"],
        'bin 32' => [65536, "\xc6"],
    ]);

    it('round-trips strings in each length class', function (int $length, string $marker) {
        $text = str_repeat('a', $length);

        expect(MessagePack::encode($text)[0])->toBe($marker)
            ->and(roundTrip($text))->toBe($text);
    })->with([
        'fixstr' => [31, "\xbf"],
        'str 8' => [32, "\xd9"],
        'str 16' => [256, "\xda"],
        'str 32' => [65536, "\xdb"],
    ]);

    it('round-trips arrays in each length class', function (int $count, string $marker) {
        $list = range(1, $count);

        expect(MessagePack::encode($list)[0])->toBe($marker)
            ->and(roundTrip($list))->toBe($list);
    })->with([
        'fixarray' => [15, "\x9f"],
        'array 16' => [16, "\xdc"],
        'array 32' => [65536, "\xdd"],
    ]);

    it('round-trips maps in each length class', function (int $count, string $marker) {
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $map["k{$i}"] = $i;
        }

        expect(MessagePack::encode($map)[0])->toBe($marker)
            ->and(roundTrip($map))->toBe($map);
    })->with([
        'fixmap' => [15, "\x8f"],
        'map 16' => [16, "\xde"],
        'map 32' => [65536, "\xdf"],
    ]);

    it('round-trips integers at every width boundary', function (int $value) {
        expect(roundTrip($value))->toBe($value);
    })->with([0, 127, 128, 255, 256, 65535, 65536, 4294967295, 4294967296, PHP_INT_MAX, -1, -32, -33, -128, -129, -32768, -32769, -2147483648, -2147483649, PHP_INT_MIN]);

    it('reads every integer format, including the ones it never writes', function (string $bytes, int $value) {
        $offset = 0;

        expect(MessagePack::decode($bytes, $offset))->toBe($value);
    })->with([
        'uint 8' => ["\xcc\xff", 255],
        'uint 16' => ["\xcd\xff\xff", 65535],
        'uint 32' => ["\xce\xff\xff\xff\xff", 4294967295],
        'uint 64' => ["\xcf\x00\x00\x00\x01\x00\x00\x00\x00", 4294967296],
        'int 8' => ["\xd0\x80", -128],
        'int 16' => ["\xd1\x80\x00", -32768],
        'int 32' => ["\xd2\x80\x00\x00\x00", -2147483648],
        'int 64' => ["\xd3\xff\xff\xff\xff\xff\xff\xff\xff", -1],
    ]);

    it('reads float32 as well as float64', function () {
        $offset = 0;

        expect(MessagePack::decode("\xca" . pack('G', 1.5), $offset))->toBe(1.5)
            ->and(roundTrip(0.1))->toBe(0.1);
    });

    it('round-trips nil and booleans', function () {
        expect(roundTrip(null))->toBeNull()
            ->and(roundTrip(true))->toBeTrue()
            ->and(roundTrip(false))->toBeFalse();
    });
});

describe('malformed input', function () {
    it('refuses a format byte the spec leaves unused', function () {
        $offset = 0;

        expect(fn () => MessagePack::decode("\xc1", $offset))
            ->toThrow(CodecException::class, 'Unknown MessagePack format byte 0xc1 at offset 0.');
    });

    it('refuses a value cut short', function (string $bytes) {
        $offset = 0;

        expect(fn () => MessagePack::decode($bytes, $offset))->toThrow(CodecException::class, 'Truncated');
    })->with(['str' => ["\xa5abc"], 'uint 32' => ["\xce\x00\x01"], 'array' => ["\x92\x01"], 'nothing' => ['']]);

    it('refuses a map key that is not an int or string', function () {
        $offset = 0;

        expect(fn () => MessagePack::decode("\x81\xc3\x01", $offset))
            ->toThrow(CodecException::class, 'Map keys must be int or string.');
    });

    // PHP has no unsigned 64-bit integer; a value past PHP_INT_MAX would wrap.
    it('refuses a uint64 PHP cannot represent', function () {
        $offset = 0;

        expect(fn () => MessagePack::decode("\xcf\xff\xff\xff\xff\xff\xff\xff\xff", $offset))
            ->toThrow(CodecException::class, 'uint64 exceeds PHP_INT_MAX');
    });
});

describe('frame codecs', function () {
    it('names themselves', function () {
        expect((new JsonLinesCodec())->name())->toBe('json-lines')
            ->and((new MessagePackCodec())->name())->toStartWith('msgpack');
    });

    it('refuses a frame body that is not a map', function (object $codec, string $body) {
        $buffer = pack('N', strlen($body)) . $body;
        $offset = 0;

        expect(fn () => $codec->decodeFrame($buffer, $offset))->toThrow(CodecException::class, 'must decode to a map');
    })->with([
        'json-lines' => [new JsonLinesCodec(), '42'],
        'msgpack' => [new MessagePackCodec(), "\x2a"],
    ]);

    it('waits for a frame that has not fully arrived', function () {
        $codec = new MessagePackCodec();
        $frame = $codec->encodeFrame(['t' => 'done']);
        $offset = 0;

        expect($codec->decodeFrame(substr($frame, 0, 2), $offset))->toBeNull()
            ->and($codec->decodeFrame(substr($frame, 0, -1), $offset))->toBeNull()
            ->and($codec->decodeFrame($frame, $offset))->toBe(['t' => 'done']);
    });

    it('refuses a frame declared larger than the cap before reading it', function () {
        $offset = 0;

        expect(fn () => (new MessagePackCodec(maxFrameBytes: 16))->decodeFrame(pack('N', 17) . str_repeat("\x00", 17), $offset))
            ->toThrow(CodecException::class, 'exceeds the 16-byte cap');
    });
});
