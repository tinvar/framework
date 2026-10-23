<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * An outcome the framework turns into a status code rather than a stack trace.
 *
 * The message is for logs. Error pages render from the status, so nothing here
 * reaches the browser unless an error page chooses to show it.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly string $code_ = '',
    ) {
        parent::__construct($message);
    }
}
