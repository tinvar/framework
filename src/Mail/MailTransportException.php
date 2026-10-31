<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A message the transport could not hand over, and the server's reason when
 * it gave one.
 *
 * Retryable or not. Retryable is the transport's trouble -- a server down
 * or slow, a 429 or a 5xx, a refused connection, a key a provider no longer
 * takes -- which the same message sent later, or through another transport,
 * may get past: a queued SendMail tries it again with its backoff, and a
 * FailoverTransport hands it to the next transport at once. Not retryable
 * is the message's own: a provider's 400 or 422 that says an address or a
 * header is wrong, an SMTP server's 5xx to a reader or to the data. Sending
 * it again sends it to the same refusal, so a queued send fails at once
 * (SendMail) and a failover stops there.
 *
 * Its message names what failed and the provider's reason, through the
 * Redactor's patterns as every log line is, and never a key, a token or a
 * password: a transport builds it from the response, never from the request
 * it signed.
 */
final class MailTransportException extends \RuntimeException
{
    /**
     * @param bool $retryable whether the same message may get through later or elsewhere
     * @param int|null $status the provider's HTTP status, or the SMTP reply's code
     * @param string|null $transport which transport refused it: `resend`, `ses`, `smtp`...
     */
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        public readonly ?int $status = null,
        public readonly ?string $transport = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** A refusal of the message itself: sent again, it is refused again. */
    public static function permanent(string $message, ?int $status = null, ?string $transport = null, ?\Throwable $previous = null): self
    {
        return new self($message, false, $status, $transport, $previous);
    }

    /** The transport's trouble: later, or through another, the message may go. */
    public static function retryable(string $message, ?int $status = null, ?string $transport = null, ?\Throwable $previous = null): self
    {
        return new self($message, true, $status, $transport, $previous);
    }

    /**
     * Whether an HTTP status says to try again: a timeout, a conflict a
     * provider asks to be retried, too many requests, any 5xx -- and a key or
     * an account the provider refused (401, 403), which is the transport's
     * configuration and not the message, and which a failover gets past.
     * Every other 4xx is the message's own.
     */
    public static function retryableStatus(int $status): bool
    {
        return $status >= 500 || \in_array($status, [401, 403, 408, 409, 425, 429], true);
    }
}
