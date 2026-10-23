<?php

declare(strict_types=1);

namespace Tinvar\Codec;

/**
 * The alternative the spec leaves open ("Socket message format: MessagePack,
 * tentative until benchmarked against JSON lines", tinvar-spec.md:955).
 *
 * Kept wire-comparable: same 4-byte length prefix, same frame maps. Binary
 * chunks have to be base64'd, which is exactly the cost the benchmark measures.
 */
final class JsonLinesCodec implements FrameCodec
{
    use LengthPrefixed;

    public function name(): string
    {
        return 'json-lines';
    }

    public function encodeFrame(array $frame): string
    {
        return $this->frame(json_encode(
            self::liftBinary($frame),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }

    public function decodeFrame(string $buffer, int &$offset): ?array
    {
        $body = $this->unframe($buffer, $offset);
        if ($body === null) {
            return null;
        }

        $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($value)) {
            throw new CodecException('Frame body must decode to a map.');
        }

        /** @var array<string, mixed> $value */
        return self::lowerBinary($value);
    }

    private static function liftBinary(mixed $v): mixed
    {
        if ($v instanceof Bin) {
            return ['$b64' => base64_encode($v->bytes)];
        }
        if (\is_array($v)) {
            return array_map(self::liftBinary(...), $v);
        }

        return $v;
    }

    private static function lowerBinary(mixed $v): mixed
    {
        if (\is_array($v)) {
            if (\count($v) === 1 && \is_string($v['$b64'] ?? null)) {
                return base64_decode($v['$b64'], true) ?: '';
            }

            return array_map(self::lowerBinary(...), $v);
        }

        return $v;
    }
}
