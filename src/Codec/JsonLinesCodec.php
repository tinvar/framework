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
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    public function decodeFrame(string $buffer, int &$offset): ?array
    {
        $body = $this->unframe($buffer, $offset);
        if ($body === null) {
            return null;
        }

        // The budget the MessagePack decoder keeps, counted before anything
        // is built: json_decode() would make a PHP array of every element,
        // and a frame under the size cap of nothing but `[],` came to ten
        // times its size -- past the memory limit, a fatal error no catch
        // can answer.
        if (!self::withinElements($body, MessagePack::MAX_ELEMENTS)) {
            throw new CodecException('Frame holds more than ' . MessagePack::MAX_ELEMENTS . ' elements.');
        }

        $value = json_decode($body, true, MessagePack::MAX_DEPTH, JSON_THROW_ON_ERROR);
        if (!\is_array($value)) {
            throw new CodecException('Frame body must decode to a map.');
        }

        /** @var array<string, mixed> $value */
        return self::lowerBinary($value);
    }

    /**
     * Whether $body holds at most $budget elements, counting each `[` and
     * `{` -- a container, empty or not -- and each `,` outside a string:
     * between them, at least every value a container holds. Strings are
     * stepped over whole, escapes and all, so text full of commas costs
     * nothing. Malformed JSON is left for json_decode() to refuse.
     */
    private static function withinElements(string $body, int $budget): bool
    {
        $n = \strlen($body);
        for ($i = strcspn($body, '",[{'); $i < $n; $i += strcspn($body, '",[{', $i)) {
            if ($body[$i] !== '"') {
                if (--$budget < 0) {
                    return false;
                }
                $i++;
                continue;
            }

            // A string: to its closing quote, past every escaped character.
            for ($i++; $i < $n; $i += 2) {
                $i += strcspn($body, '"\\', $i);
                if ($i >= $n || $body[$i] === '"') {
                    break;
                }
            }
            $i++;
        }

        return true;
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
                // Against false, not falsy: the single byte "0" is a chunk
                // like any other, and `?:` turned it into nothing.
                $bytes = base64_decode($v['$b64'], true);

                return $bytes === false ? '' : $bytes;
            }

            return array_map(self::lowerBinary(...), $v);
        }

        return $v;
    }
}
