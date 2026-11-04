<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Mail\MailTransportException;
use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Observability\SafeReason;

/**
 * How a mail provider's API is called: through the application's one
 * OutboundHttp, held to the provider's own origin, within a timeout, and
 * following no redirect -- a 3xx from an API is a failure, never somewhere
 * else to send a key to. And a permanent one: an API that redirects its
 * send endpoint is a misconfiguration -- a wrong host or region, a proxy in
 * the way -- that the same request will meet again, later or retried, so
 * no backoff spends the provider's patience on it, and a failover, which
 * goes on only for the transport's passing trouble, stops there.
 *
 * And how it fails. A request refused before it left, or one that did not
 * finish -- a timeout, a refused connection, TLS -- is retryable: nothing
 * about the message was judged. A response is judged by its status
 * (MailTransportException::retryableStatus()), or by what the provider's
 * own error code says where that knows better: Postmark's 422 for a bad
 * token, SES's 400 for a paused account.
 *
 * The exception's message names the provider, the status and the
 * provider's own reason -- its JSON `message`, cut to a line -- with every
 * secret the transport holds cut out, and then the Redactor's patterns: a
 * provider that quotes the key it was sent back in its error, or the
 * reader's address, says neither in a log line or a failed job.
 */
final readonly class ProviderHttp
{
    /** Seconds a provider's API is given, unless the transport says otherwise. */
    public const float DEFAULT_TIMEOUT = 15.0;

    /** The most of a response read: an id, or an error. */
    public const int MAX_RESPONSE_BYTES = 65_536;

    /** The most of a provider's reason quoted in an exception. */
    private const int MAX_REASON = 300;

    private Destinations $destinations;

    /**
     * @param string $transport the provider's name, as SentMessage and the exception say it
     * @param string $label the provider's name as a person reads it: `Resend`, `Amazon SES`
     * @param list<string> $hosts the hosts its API answers at: every request is held to them
     * @param (\Closure(string): string)|null $scrub cuts what the transport holds and will not reveal
     *        -- an AWS secret -- out of an error's text
     */
    public function __construct(
        private OutboundHttp $http,
        public string $transport,
        private string $label,
        array $hosts,
        private float $timeout = self::DEFAULT_TIMEOUT,
        private ?\Closure $scrub = null,
    ) {
        $this->destinations = Destinations::hosts($hosts);
    }

    /**
     * The same, given $seconds at most: its own timeout when that is
     * shorter. What a failover holds a provider to (Bounded).
     */
    public function within(float $seconds): self
    {
        return clone($this, ['timeout' => min($this->timeout, $seconds)]);
    }

    /**
     * Sends one request to the provider, and returns its 2xx response.
     *
     * @param array<string, string> $headers
     * @param list<string> $secrets every secret the request carries, to cut from what is thrown
     * @param (\Closure(OutboundResponse): ?bool)|null $retryable the provider's own word on
     *        whether a failed response is retryable; null to go by its status
     * @throws MailTransportException
     */
    public function send(
        string $method,
        string $url,
        #[\SensitiveParameter]
        array $headers,
        #[\SensitiveParameter]
        string $body,
        #[\SensitiveParameter]
        array $secrets,
        ?\Closure $retryable = null,
    ): OutboundResponse {
        try {
            $response = $this->http->send(new OutboundRequest(
                $method,
                $url,
                $headers,
                $body,
                timeout: $this->timeout,
                maxResponseBytes: self::MAX_RESPONSE_BYTES,
                maxRedirects: 0,
                destinations: $this->destinations,
            ));
        } catch (OutboundRefused $e) {
            throw MailTransportException::retryable("{$this->label} was not called: {$e->reason}. " . $this->clean($e->getMessage(), $secrets), null, $this->transport);
        } catch (OutboundFailed $e) {
            throw MailTransportException::retryable("{$this->label} did not answer: {$e->reason}. " . $this->clean($e->getMessage(), $secrets), $e->status, $this->transport);
        }

        if ($response->successful()) {
            return $response;
        }

        $reason = self::reason($response);
        $message = "{$this->label} answered {$response->status}" . ($reason === null ? '.' : ': ' . $this->clean($reason, $secrets));
        $again = $retryable === null ? null : $retryable($response);

        throw new MailTransportException($message, $again ?? MailTransportException::retryableStatus($response->status), $response->status, $this->transport);
    }

    /**
     * The response as JSON, or an empty array when it is not: an id missing
     * from an accepted send is no reason to send it again.
     *
     * @return array<array-key, mixed>
     */
    public static function json(OutboundResponse $response): array
    {
        try {
            return $response->json();
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * $value as JSON, or a permanent failure: a message that does not encode
     * -- its HTML not UTF-8 -- will not encode the next time either.
     *
     * @param array<array-key, mixed> $value
     * @throws MailTransportException
     */
    public function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw MailTransportException::permanent("The message could not be written as JSON for {$this->label}: {$e->getMessage()}", null, $this->transport);
        }
    }

    /** What the provider said was wrong: the commonest shapes of a JSON error. */
    private static function reason(OutboundResponse $response): ?string
    {
        $json = self::json($response);
        $candidates = [
            $json['message'] ?? null,
            $json['Message'] ?? null,
            $json['error'] ?? null,
            \is_array($json['errors'] ?? null) && \is_array($json['errors'][0] ?? null) ? ($json['errors'][0]['message'] ?? null) : null,
        ];
        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * $text on one line, cut, with each secret and anything else that looks
     * like one taken out.
     *
     * @param list<string> $secrets
     */
    private function clean(string $text, #[\SensitiveParameter] array $secrets): string
    {
        return SafeReason::of($text, $secrets, $this->scrub, self::MAX_REASON);
    }
}
