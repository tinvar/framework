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
    /**
     * @param array<string, string> $headers headers the response must carry
     *        even though it is a refusal -- Retry-After on a 429, and the
     *        rate-limit counters a client needs to slow itself down. Without
     *        somewhere to put them, a throttle that computed them correctly
     *        still answered with none of them.
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly string $code_ = '',
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
