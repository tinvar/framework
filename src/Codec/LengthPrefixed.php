<?php

declare(strict_types=1);

namespace Tinvar\Codec;

/**
 * The 4-byte big-endian length prefix shared by every codec, with the frame-size
 * cap the spec requires of the socket ("Frames are capped at a configured size").
 */
trait LengthPrefixed
{
    public function __construct(private int $maxFrameBytes = self::DEFAULT_MAX_FRAME_BYTES) {}

    public const int DEFAULT_MAX_FRAME_BYTES = 16 * 1024 * 1024;

    private function frame(string $body): string
    {
        $n = \strlen($body);
        if ($n > $this->maxFrameBytes) {
            throw new CodecException("Frame of {$n} bytes exceeds the {$this->maxFrameBytes}-byte cap.");
        }

        return pack('N', $n) . $body;
    }

    /** Returns the frame body, or null when the buffer is short. */
    private function unframe(string $buffer, int &$offset): ?string
    {
        if ($offset + 4 > \strlen($buffer)) {
            return null;
        }
        $header = unpack('N', substr($buffer, $offset, 4));
        if ($header === false) {
            throw new CodecException('Truncated frame header.');
        }
        $n = (int) $header[1];
        if ($n > $this->maxFrameBytes) {
            throw new CodecException("Incoming frame of {$n} bytes exceeds the {$this->maxFrameBytes}-byte cap.");
        }
        if ($offset + 4 + $n > \strlen($buffer)) {
            return null;
        }
        $body = substr($buffer, $offset + 4, $n);
        $offset += 4 + $n;

        return $body;
    }
}
