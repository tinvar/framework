<?php

declare(strict_types=1);

namespace Tinvar\Protocol;

final class ProtocolException extends \RuntimeException
{
    public static function wrongDirection(string $type): self
    {
        return new self(
            "The renderer may not send a '{$type}' frame. It may send only: "
            . implode(', ', Frame::RENDERER_TO_PHP) . '.',
        );
    }

    public static function unknownType(mixed $type): self
    {
        return new self('Frame has a missing or non-string type: ' . get_debug_type($type) . '.');
    }

    public static function malformed(string $type, string $why): self
    {
        return new self("Malformed '{$type}' frame: {$why}.");
    }
}
