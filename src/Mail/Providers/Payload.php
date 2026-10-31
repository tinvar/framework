<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Http\HttpException;

/**
 * What every webhook reads a verified delivery with: its JSON, the
 * addresses and times in it, and the refusals it answers with. Values are
 * read as the shapes they should be and nothing else -- an address that is
 * not one is left out rather than suppressed.
 */
final class Payload
{
    /** The deepest JSON a webhook body is read to. */
    private const int DEPTH = 32;

    /**
     * $raw as a JSON object or array, or a 400.
     *
     * @return array<array-key, mixed>
     */
    public static function json(string $raw): array
    {
        try {
            $data = json_decode($raw, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'The webhook body is not JSON.', 'bad_request');
        }
        if (!\is_array($data)) {
            throw new HttpException(400, 'The webhook body is not a JSON object or array.', 'bad_request');
        }

        return $data;
    }

    /** An address, lowercased, from `ada@example.test` or `"Ada" <ada@example.test>`; null when it is not one. */
    public static function email(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        if (preg_match('/<([^<>]+)>\s*\z/', $value, $m) === 1) {
            $value = $m[1];
        }
        $value = strtolower(trim($value));

        return \strlen($value) <= 320 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    /** A non-empty string, cut to $max bytes, or null. */
    public static function text(mixed $value, int $max = 500): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strcut($value, 0, $max, 'UTF-8');
    }

    /** Seconds since the epoch from a number or an ISO 8601 time; $fallback when it is neither. */
    public static function time(mixed $value, int $fallback): int
    {
        if (\is_int($value) || \is_float($value)) {
            return (int) $value;
        }
        if (\is_string($value) && $value !== '') {
            if (ctype_digit($value)) {
                return (int) $value;
            }
            $parsed = strtotime($value);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return $fallback;
    }

    /** @return array<array-key, mixed> */
    public static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /** The 401 every delivery that does not prove itself gets, whatever the reason. */
    public static function unverified(string $why): HttpException
    {
        return new HttpException(401, $why, 'webhook_unverified');
    }

    /** A timestamp of a signed delivery, held to $tolerance of now either way. */
    public static function assertFresh(int $timestamp, int $now, int $tolerance): void
    {
        if (abs($now - $timestamp) > $tolerance) {
            throw self::unverified('The delivery\'s timestamp is outside the tolerance: sent too long ago, or by a clock far off.');
        }
    }
}
