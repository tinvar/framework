<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * What an #[ErrorPage]'s loader is told about the failure: the status, the
 * machine code and fixed reason the framework's own error response carries,
 * and the digest its log line is under.
 *
 * The same three things the JSON answer gives an API client, and no more: an
 * error page that could read the exception would be an error page that
 * could show its message, and messages name classes, rows and policies.
 */
final readonly class HttpError
{
    /** Fixed reason phrases. Keyed by status, never by exception message. */
    private const array REASONS = [
        400 => 'The request could not be understood.',
        401 => 'Authentication is required.',
        403 => 'You may not do that.',
        404 => 'Not found.',
        405 => 'That method is not allowed here.',
        413 => 'The request is too large.',
        415 => 'That kind of content is not taken here.',
        419 => 'The session has expired.',
        422 => 'The request could not be processed.',
        409 => 'The request conflicts with the current state.',
        429 => 'Too many requests; slow down.',
        500 => 'Something went wrong.',
    ];

    public function __construct(
        public int $status,
        public string $code,
        public string $reason,
        public string $digest,
    ) {}

    /**
     * The error a refusal says that was returned rather than thrown: nothing
     * was logged for it, so there is no line to point at, and a fresh value
     * still tells two reports apart. A thrown one is described by the
     * exception handler, under the digest it was logged with.
     */
    public static function returned(int $status): self
    {
        return new self($status, self::codeFor($status), self::reason($status), bin2hex(random_bytes(8)));
    }

    /** The fixed reason phrase for a status: never an exception's message. */
    public static function reason(int $status): string
    {
        return self::REASONS[$status] ?? self::REASONS[500];
    }

    /** The machine-readable code the framework gives a status when nothing more specific was thrown. */
    public static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            413 => 'body_too_large',
            415 => 'unsupported_media_type',
            419 => 'csrf_token_mismatch',
            409 => 'conflict',
            422 => 'unprocessable',
            429 => 'too_many_requests',
            default => 'internal_error',
        };
    }
}
