<?php

declare(strict_types=1);

namespace Tinvar\Codec;

/**
 * The spec's socket format. Uses ext-msgpack when loaded and the pure-PHP
 * implementation otherwise; both produce identical wire bytes for the subset
 * the protocol uses.
 */
final class MessagePackCodec implements FrameCodec
{
    use LengthPrefixed;

    private static ?bool $native = null;

    public static function hasNativeExtension(): bool
    {
        return self::$native ??= \extension_loaded('msgpack');
    }

    public function name(): string
    {
        return self::hasNativeExtension() ? 'msgpack (ext)' : 'msgpack (php)';
    }

    public function encodeFrame(array $frame): string
    {
        return $this->frame(
            self::hasNativeExtension() ? msgpack_pack($frame) : MessagePack::encode($frame)
        );
    }

    public function decodeFrame(string $buffer, int &$offset): ?array
    {
        $body = $this->unframe($buffer, $offset);
        if ($body === null) {
            return null;
        }

        $value = self::hasNativeExtension() ? msgpack_unpack($body) : MessagePack::decode($body);
        if (!\is_array($value)) {
            throw new CodecException('Frame body must decode to a map.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
