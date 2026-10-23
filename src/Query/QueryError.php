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
}
