<?php

declare(strict_types=1);

namespace Tinvar\Query;

/**
 * "Query errors sent to the renderer carry a classified code and digest in
 * production, never exception messages." (tinvar-spec.md, "Renderer hardening")
 */
final class QueryError
{
    public const string NOT_ALLOWED = 'query_not_allowed';
    public const string UNKNOWN = 'query_unknown';
    public const string FORBIDDEN = 'forbidden';
    public const string FAILED = 'query_failed';
    public const string BAD_RESULT = 'query_bad_result';
    public const string BAD_ARGUMENTS = 'query_bad_arguments';
    public const string NOT_FOUND = 'query_not_found';
    public const string MISCONFIGURED = 'query_misconfigured';

    /** @return array{code: string, digest: string, message?: string} */
    public static function of(string $code, \Throwable|string $cause, bool $debug): array
    {
        $detail = $cause instanceof \Throwable
            ? $cause::class . ': ' . $cause->getMessage()
            : $cause;

        $error = ['code' => $code, 'digest' => substr(hash('xxh128', $detail), 0, 16)];

        if ($debug) {
            $error['message'] = $detail;
        }

        return $error;
    }

    /**
     * The HTTP status one of these codes means over /_query.
     *
     * Over the socket a QueryError is a per-call record, which is right: one
     * bad call in a tick of 42 must not lose the other 41. Over HTTP there is
     * one call, and the endpoint was collapsing every code to 422 -- so a
     * denial, an unknown name and bad input were indistinguishable. The spec's
     * client-held-data rules need "re-authenticate and drop what you hold"
     * (403) to be tellable from "fix your input" (422).
     */
    public static function statusFor(string $code): int
    {
        return match ($code) {
            self::FORBIDDEN => 403,
            self::NOT_FOUND => 404,
            self::NOT_ALLOWED, self::UNKNOWN => 404,
            self::BAD_ARGUMENTS => 422,
            self::FAILED, self::BAD_RESULT, self::MISCONFIGURED => 500,
            default => 422,
        };
    }
}
