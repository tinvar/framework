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
    public function __construct(
        public int $status,
        public string $code,
        public string $reason,
        public string $digest,
    ) {}

    /**
     * The error a refusal says that was returned rather than thrown: nothing
     * was logged for it, so there is no line to point at, and a fresh value
     * still tells two reports apart. A thrown one is described by
     * ExceptionHandler::errorFor(), under the digest it was logged with.
     */
    public static function returned(int $status): self
    {
        return new self($status, ExceptionHandler::codeFor($status), ExceptionHandler::reason($status), bin2hex(random_bytes(8)));
    }
}
