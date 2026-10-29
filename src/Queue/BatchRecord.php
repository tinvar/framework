<?php

declare(strict_types=1);

namespace Tinvar\Queue;

/**
 * A batch of jobs as its queue keeps it: whose it is, how many of its jobs
 * are still to end and how many failed, and the jobs to push when it
 * finishes or first fails.
 *
 * Bound as a transfer's record is to where it was dispatched -- the tenant,
 * the panel and the user -- and read back only there (the dispatcher's
 * batchProgress()). Its callbacks are sealed payloads, each with the
 * tenant, panel, user and epoch the batch was dispatched with.
 */
final readonly class BatchRecord
{
    public const string THEN = 'then';
    public const string CATCH = 'catch';
    public const string FINALLY = 'finally';

    /**
     * @param string $tenant '' for none
     * @param string $panel '' for none
     * @param string $user '' for nobody
     * @param list<array{on: self::THEN|self::CATCH|self::FINALLY, job: PendingJob}> $callbacks
     */
    public function __construct(
        public string $id,
        public string $tenant,
        public string $panel,
        public string $user,
        public int $total,
        public int $pending,
        public int $failed,
        public bool $allowFailures,
        public int $createdAt,
        public ?int $cancelledAt = null,
        public ?int $finishedAt = null,
        public array $callbacks = [],
    ) {}

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * The callbacks for $on, ready to push now.
     *
     * @param self::THEN|self::CATCH|self::FINALLY $on
     * @return list<PendingJob>
     */
    public function callbacksFor(string $on, int $now): array
    {
        $jobs = [];
        foreach ($this->callbacks as $callback) {
            if ($callback['on'] === $on) {
                $jobs[] = new PendingJob($callback['job']->id, $callback['job']->queue, $callback['job']->payload, $now);
            }
        }

        return $jobs;
    }

    /** The callbacks as a store keeps them: JSON. */
    public function callbacksJson(): string
    {
        return json_encode(array_map(static fn (array $callback): array => [
            'on' => $callback['on'],
            'id' => $callback['job']->id,
            'queue' => $callback['job']->queue,
            'payload' => $callback['job']->payload,
        ], $this->callbacks), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Callbacks a store read back; anything that is not what callbacksJson()
     * writes is dropped -- its payloads are verified when they run, and one
     * that is not even shaped as one never gets that far.
     *
     * @return list<array{on: self::THEN|self::CATCH|self::FINALLY, job: PendingJob}>
     */
    public static function callbacksFrom(mixed $json): array
    {
        $decoded = \is_string($json) ? json_decode($json, true) : null;
        $callbacks = [];
        foreach (\is_array($decoded) ? $decoded : [] as $entry) {
            if (!\is_array($entry) || !\is_string($entry['id'] ?? null) || !\is_string($entry['queue'] ?? null) || !\is_string($entry['payload'] ?? null)) {
                continue;
            }
            $on = match ($entry['on'] ?? null) {
                self::THEN => self::THEN,
                self::CATCH => self::CATCH,
                self::FINALLY => self::FINALLY,
                default => null,
            };
            if ($on !== null) {
                $callbacks[] = ['on' => $on, 'job' => new PendingJob($entry['id'], $entry['queue'], $entry['payload'], 0)];
            }
        }

        return $callbacks;
    }
}
