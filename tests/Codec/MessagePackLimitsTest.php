<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codec\Limits;

use Tinvar\Codec\CodecException;
use Tinvar\Codec\MessagePack;
use Tinvar\Codec\MessagePackCodec;

// What the renderer sends is decoded by the trusted process, and the renderer
// runs third-party npm code. A frame under the size cap still took PHP past
// its memory limit -- a fatal error no catch can answer -- so the decoder
// refuses a frame before it builds it.

it('refuses nesting deeper than any frame has', function () {
    $bomb = str_repeat("\x91", MessagePack::MAX_DEPTH + 1) . "\xc0";

    expect(fn () => MessagePack::decode($bomb))->toThrow(CodecException::class, 'nests deeper');
});

it('decodes nesting up to the limit', function () {
    $deep = str_repeat("\x91", MessagePack::MAX_DEPTH - 1) . "\xc0";

    $value = MessagePack::decode($deep);
    for ($i = 1; $i < MessagePack::MAX_DEPTH; $i++) {
        $value = $value[0];
    }

    expect($value)->toBeNull();
});

it('refuses more elements than a frame may hold, before building them', function () {
    $many = "\xdd" . pack('N', MessagePack::MAX_ELEMENTS + 1) . str_repeat("\xc0", MessagePack::MAX_ELEMENTS + 1);

    expect(fn () => MessagePack::decode($many))->toThrow(CodecException::class, 'more than');
});

it('counts the budget across the whole frame, not per container', function () {
    $half = intdiv(MessagePack::MAX_ELEMENTS, 2) + 1;
    $list = "\xdd" . pack('N', $half) . str_repeat("\xc0", $half);

    expect(fn () => MessagePack::decode("\x92" . $list . $list))->toThrow(CodecException::class, 'more than');
});

it('refuses a count the remaining bytes could not hold', function () {
    expect(fn () => MessagePack::decode("\xdd\xff\xff\xff\xff\xc0"))->toThrow(CodecException::class);
});

it('still decodes an ordinary frame', function () {
    $frame = ['t' => 'query', 'q' => [['id' => 1, 'name' => 'racks.summary', 'args' => ['rack' => 'r1']]]];

    expect(MessagePack::decode(MessagePack::encode($frame)))->toBe($frame);
});

describe('a frame bound for the renderer', function () {
    it('holds only arrays and scalars', function (mixed $value) {
        expect(fn () => MessagePackCodec::assertPlain(['data' => ['nested' => $value]]))
            ->toThrow(CodecException::class, 'only arrays and scalars');
    })->with([
        'object' => [new \stdClass()],
        'date' => [new \DateTimeImmutable()],
    ]);

    it('holds no closure', function () {
        expect(fn () => MessagePackCodec::assertPlain(['data' => [static fn (): int => 1]]))
            ->toThrow(CodecException::class, 'Cannot encode Closure');
    });

    it('lets arrays and scalars through', function () {
        MessagePackCodec::assertPlain(['a' => [1, 'b', 2.5, true, null, ['c' => []]]]);

        expect(true)->toBeTrue();
    });
});
