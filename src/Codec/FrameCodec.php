<?php

declare(strict_types=1);

namespace Tinvar\Codec;

/**
 * Framing for the PHP-to-renderer socket.
 *
 * Every message is a 4-byte big-endian length followed by an encoded map
 * carrying a `t` (type) key (tinvar-spec.md, "Socket protocol").
 */
interface FrameCodec
{
    /** Wire name, used in logs, the dev toolbar and benchmark output. */
    public function name(): string;

    /** @param array<string, mixed> $frame */
    public function encodeFrame(array $frame): string;

    /**
     * Decode one frame from $buffer starting at $offset, advancing $offset past it.
     * Returns null when the buffer does not yet hold a whole frame, leaving $offset put.
     *
     * @return array<string, mixed>|null
     */
    public function decodeFrame(string $buffer, int &$offset): ?array;
}
