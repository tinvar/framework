<?php

declare(strict_types=1);

namespace Tinvar\Live;

/**
 * One worker's way to a remote hub (Mercure::remote()), held for as long as
 * the worker lives: one curl handle, reused, so an update does not pay for
 * a new connection; a deadline on what one request may spend publishing;
 * and a breaker that stops asking a hub that is down.
 *
 * A publish runs after the write it tells of has committed, in the request
 * that made it, and the response waits on it. Unbounded, a hub that did not
 * answer held every write for the stream timeout once per topic -- five
 * seconds each, three topics fifteen. Now:
 *
 *   - connecting may take CONNECT_TIMEOUT, and the whole POST TIMEOUT, or
 *     less where the request's deadline has less left;
 *   - one request spends at most REQUEST_BUDGET publishing, in all; past
 *     it, its remaining updates are skipped. A request ends at
 *     endRequest(), which the Kernel's request scope calls; a process that
 *     ends none -- a queue worker, a scheduler -- has its budget renewed
 *     once its first publish is WINDOW old;
 *   - a hub that could not be reached, timed out or answered 5xx is not
 *     asked again for COOLDOWN seconds: an update meanwhile is skipped at
 *     once rather than waiting out the same timeout.
 *
 * A skipped update throws HubUnavailable, as a failed one throws, and is
 * logged where its caller logs a failed publish (Invalidator, Channels):
 * the hub keeps no history (ADR 0048), so a page that missed an update
 * shows its data until it next loads it, and the log is where it is seen.
 */
final class HubLink
{
    /** Seconds connecting to the hub may take. */
    public const float CONNECT_TIMEOUT = 1.0;

    /** Seconds one POST may take, connecting included. */
    public const float TIMEOUT = 2.0;

    /** Seconds one request may spend publishing, every update together. */
    public const float REQUEST_BUDGET = 3.0;

    /** Seconds a hub that failed is left alone for. */
    public const float COOLDOWN = 15.0;

    /** Seconds after which a process that ends no request has its budget renewed. */
    public const float WINDOW = 10.0;

    private ?\CurlHandle $curl = null;

    /** When the hub may be asked again; 0 while it is answering. */
    private float $downUntil = 0.0;

    /** Why it was last taken for down. */
    private string $downBecause = '';

    /** When this request's first publish began, or null before it. */
    private ?float $from = null;

    /** Seconds this request has spent publishing. */
    private float $spent = 0.0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(string, array<string, string>, string, float): int)|null $post
     *        sends one POST within the seconds given and returns its status,
     *        throwing where the hub could not be reached; curl unless given
     * @param (\Closure(): float)|null $clock seconds, as microtime(true) gives them
     */
    public function __construct(
        private readonly ?\Closure $post = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param array<string, string> $headers
     * @throws HubUnavailable where the update was skipped, or the hub could not be reached
     * @throws \RuntimeException where the hub answered and refused it
     */
    public function send(string $url, array $headers, string $body): void
    {
        $now = ($this->clock)();
        if ($now < $this->downUntil) {
            throw new HubUnavailable(\sprintf(
                'The update was not sent: the hub at %s is taken for down for %ds more (%s).',
                $url,
                (int) ceil($this->downUntil - $now),
                $this->downBecause,
            ));
        }

        if ($this->from === null || $now - $this->from >= self::WINDOW) {
            $this->from = $now;
            $this->spent = 0.0;
        }
        $left = self::REQUEST_BUDGET - $this->spent;
        if ($left <= 0.0) {
            throw new HubUnavailable(\sprintf(
                'The update was not sent: this request has spent the %.1fs it may spend publishing to the hub at %s.',
                self::REQUEST_BUDGET,
                $url,
            ));
        }

        try {
            $status = ($this->post ?? $this->curlPost(...))($url, $headers, $body, min(self::TIMEOUT, $left));
        } catch (\Throwable $e) {
            $this->down($e->getMessage());

            throw $e instanceof HubUnavailable ? $e : new HubUnavailable("The hub at {$url} could not be reached: {$e->getMessage()}", 0, $e);
        } finally {
            $this->spent += max(0.0, ($this->clock)() - $now);
        }

        if ($status === 0 || $status >= 500) {
            $this->down("it answered {$status}");

            throw new HubUnavailable("The hub at {$url} failed an update with {$status}.");
        }
        if ($status < 200 || $status >= 300) {
            // Answered, and refused: a key or a topic the hub will not take,
            // which waiting does not change -- and costs nothing to ask.
            throw new \RuntimeException("The hub refused an update with {$status}.");
        }
    }

    /** The request is over: the next one has a whole budget. */
    public function endRequest(): void
    {
        $this->from = null;
        $this->spent = 0.0;
    }

    private function down(string $because): void
    {
        $this->downUntil = ($this->clock)() + self::COOLDOWN;
        $this->downBecause = $because;
    }

    /** @param array<string, string> $headers */
    private function curlPost(string $url, array $headers, string $body, float $timeout): int
    {
        if ($url === '') {
            throw new HubUnavailable('No hub URL was given to publish to.');
        }
        if (!\extension_loaded('curl')) {
            return self::streamPost($url, $headers, $body, $timeout);
        }

        // One handle for the worker's life: curl keeps its connection to the
        // hub open between updates, and its DNS answer.
        $curl = $this->curl ??= curl_init();
        curl_reset($curl);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min(self::CONNECT_TIMEOUT, $timeout) * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            // A hub answers itself; a redirect would carry the token elsewhere.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if (curl_exec($curl) === false) {
            throw new HubUnavailable("The hub at {$url} could not be reached: " . curl_error($curl));
        }

        return (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    }

    /** @param array<string, string> $headers */
    private static function streamPost(string $url, array $headers, string $body, float $timeout): int
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $lines),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            // A hub answers itself; a redirect would carry the token elsewhere.
            'follow_location' => 0,
        ]]);

        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            throw new HubUnavailable("The hub at {$url} could not be reached.");
        }

        try {
            $meta = stream_get_meta_data($stream);
            /** @var list<string> $responseHeaders */
            $responseHeaders = $meta['wrapper_data'] ?? [];
        } finally {
            fclose($stream);
        }

        return preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0] ?? '', $m) === 1 ? (int) $m[1] : 0;
    }
}
