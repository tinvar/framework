<?php

declare(strict_types=1);

namespace Tinvar\Http\Endpoints;

use Tinvar\Http\HttpException;

/**
 * The endpoint limits from the spec's table.
 *
 * "Body 1 MB (uploads separate), JSON depth 32." Enforced at the decode
 * boundary, which is the only place that can: everything downstream has
 * already paid for whatever arrived.
 */
final class Limits
{
    public const int MAX_BODY_BYTES = 1_048_576;
    public const int MAX_JSON_DEPTH = 32;

    /**
     * Keys and elements in one body, counted before it is decoded.
     *
     * PHP's arrays are not hash-randomised, so a megabyte of keys chosen to
     * collide took seconds of CPU to decode -- before the action or query
     * name was even looked at, and so for anyone who could reach the
     * endpoint. Ten thousand is far beyond any form or argument list.
     */
    public const int MAX_JSON_ELEMENTS = 10_000;

    /**
     * How many fields one Precognition-Validate-Only header may name.
     *
     * Each named field re-runs model resolution and authorization, and the
     * header is client-supplied, so it needs its own bound: the body limit
     * does not cover it.
     */
    public const int MAX_PRECOGNITION_FIELDS = 32;

    /**
     * Decodes a JSON body, refusing one that is too large or too deep.
     *
     * @return array<string, mixed>
     */
    public static function decodeJson(string $body, int $maxBytes = self::MAX_BODY_BYTES): array
    {
        if (\strlen($body) > $maxBytes) {
            throw new HttpException(413, 'Request body is too large.', 'body_too_large');
        }

        if (trim($body) === '') {
            return [];
        }

        if (self::separators($body) > self::MAX_JSON_ELEMENTS) {
            throw new HttpException(413, 'Request body has too many fields.', 'body_too_large');
        }

        try {
            $decoded = json_decode($body, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Depth and syntax both land here. The message is not forwarded:
            // it quotes the body.
            throw new HttpException(400, 'Request body is not valid JSON within the depth limit.', 'bad_json');
        }

        // PHP decodes {} to [], and array_is_list([]) is true -- so the
        // list guard was rejecting every argument-less action and query.
        if (!\is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new HttpException(400, 'Request body must be a JSON object.', 'bad_json');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * The same limits for a body PHP parsed itself -- a form post or a
     * multipart one -- which arrives already decoded and so never passed
     * through decodeJson(). A 5 MB field and 40 levels of nesting went
     * straight to the action. Files are not in it, and are limited apart.
     *
     * @param array<mixed> $body
     * @return array<mixed>
     */
    public static function form(array $body, int $maxBytes = self::MAX_BODY_BYTES): array
    {
        $bytes = 0;
        $elements = 0;
        $walk = static function (array $level, int $depth) use (&$walk, &$bytes, &$elements, $maxBytes): void {
            if ($depth > self::MAX_JSON_DEPTH) {
                throw new HttpException(400, 'Request body nests too deeply.', 'bad_json');
            }
            foreach ($level as $key => $value) {
                $elements++;
                $bytes += \strlen((string) $key);
                if (\is_array($value)) {
                    $walk($value, $depth + 1);
                } elseif (\is_string($value)) {
                    $bytes += \strlen($value);
                }
                if ($bytes > $maxBytes) {
                    throw new HttpException(413, 'Request body is too large.', 'body_too_large');
                }
                if ($elements > self::MAX_JSON_ELEMENTS) {
                    throw new HttpException(413, 'Request body has too many fields.', 'body_too_large');
                }
            }
        };
        $walk($body, 1);

        return $body;
    }

    /**
     * The commas and colons outside strings: one per key, and one between
     * each pair of elements, so an upper bound on what decoding would build.
     * Strings are dropped first so a comma in a sentence does not count.
     * Malformed JSON may be miscounted; it is refused by the decoder anyway.
     */
    private static function separators(string $body): int
    {
        $structure = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"/s', '', $body);
        if ($structure === null) {
            // The pattern could not run to the end (a backtrack or JIT
            // limit): too strange to decode, rather than too small to count.
            throw new HttpException(400, 'Request body is not valid JSON within the depth limit.', 'bad_json');
        }

        return substr_count($structure, ',') + substr_count($structure, ':');
    }
}
