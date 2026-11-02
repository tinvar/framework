<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * An exception that is a refusal with a status of its own, though it is no
 * HttpException -- one a module above the core throws from another family,
 * as ApiTokenRefused is an InvalidArgumentException. ExceptionHandler
 * answers one left uncaught with its status and code, the message going to
 * the log as any other's does, rather than as a 500.
 */
interface HasHttpStatus
{
    /** The status it is answered with: a 4xx. */
    public function httpStatus(): int;

    /** The machine-readable code the answer carries. */
    public function httpCode(): string;
}
