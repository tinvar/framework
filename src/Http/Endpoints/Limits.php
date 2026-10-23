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

        try {
            $decoded = json_decode($body, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Depth and syntax both land here. The message is not forwarded:
            // it quotes the body.
            throw new HttpException(400, 'Request body is not valid JSON within the depth limit.', 'bad_json');
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new HttpException(400, 'Request body must be a JSON object.', 'bad_json');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
