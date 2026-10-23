<?php

declare(strict_types=1);

namespace Tinvar\Http;

/** What one throttle check decided, and what the response should say about it. */
final readonly class ThrottleResult
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $resetsAt,
    ) {}

    public function retryAfter(?int $now = null): int
    {
        return max(1, $this->resetsAt - ($now ?? time()));
    }

    /**
     * The headers every throttled response carries, allowed or not.
     *
     * A client that can see how much it has left can slow down on its own;
     * one that only finds out by being refused cannot.
     *
     * @return array<string, string>
     */
    public function headers(?int $now = null): array
    {
        $headers = [
            'x-ratelimit-limit' => (string) $this->limit,
            'x-ratelimit-remaining' => (string) $this->remaining,
            'x-ratelimit-reset' => (string) $this->resetsAt,
        ];

        if (!$this->allowed) {
            $headers['retry-after'] = (string) $this->retryAfter($now);
        }

        return $headers;
    }
}
