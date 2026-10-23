<?php

declare(strict_types=1);

namespace Tinvar\Codec;

final class CodecException extends \RuntimeException
{
    public static function unsupported(mixed $value): self
    {
        return new self('Cannot encode value of type ' . get_debug_type($value) . '.');
    }

    public static function truncated(int $need, int $have): self
    {
        return new self("Truncated frame: needed {$need} bytes, had {$have}.");
    }

    public static function badFormat(int $byte, int $offset): self
    {
        return new self(sprintf('Unknown MessagePack format byte 0x%02x at offset %d.', $byte, $offset));
    }
}
