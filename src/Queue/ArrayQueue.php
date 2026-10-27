<?php

declare(strict_types=1);

namespace Tinvar\Queue;

use Tinvar\Orm\Connection;

/**
 * A queue in this process's memory: the queue fake the spec's testing layer
 * asks for ("Laravel-style fakes for mail, queue, HTTP, events"), and a real
 * queue for a worker in the same process, so a test can dispatch, work and
 * see retries and failures without a database.
 *
 * Not for production: nothing survives the process, and no other process
 * can see it.
 */
final class ArrayQueue implements Queue
{
    /** @var array<string, array{queue: string, payload: string, availableAt: int, attempts: int, reservedUntil: ?int, token: ?string}> */
    private array $jobs = [];

    /** @var list<FailedJob> */
    private array $failures = [];

    /** @var list<PendingJob> every job pushed, in order, for assertions */
    private array $pushed = [];

    public function push(PendingJob $job): void
    {
        $this->pushed[] = $job;
        $this->jobs[$job->id] = [
            'queue' => $job->queue,
            'payload' => $job->payload,
            'availableAt' => $job->availableAt,
            'attempts' => 0,
            'reservedUntil' => null,
            'token' => null,
        ];
    }

    public function reserve(array $queues, int $now, int $reserveFor): ?ReservedJob
    {
        foreach ($queues as $queue) {
            foreach ($this->jobs as $id => $job) {
                if ($job['queue'] !== $queue || $job['availableAt'] > $now
                    || ($job['reservedUntil'] !== null && $job['reservedUntil'] > $now)) {
                    continue;
                }

                $token = bin2hex(random_bytes(8));
                $this->jobs[$id] = [...$job, 'attempts' => $job['attempts'] + 1, 'reservedUntil' => $now + $reserveFor, 'token' => $token];

                return new ReservedJob((string) $id, $queue, $job['payload'], $job['attempts'] + 1, $token);
            }
        }

        return null;
    }

    public function extend(ReservedJob $job, int $until): bool
    {
        if (!$this->holds($job)) {
            return false;
        }
        $this->jobs[$job->id] = [...$this->jobs[$job->id], 'reservedUntil' => $until];

        return true;
    }

    public function delete(ReservedJob $job): bool
    {
        if (!$this->holds($job)) {
            return false;
        }
        unset($this->jobs[$job->id]);

        return true;
    }

    public function release(ReservedJob $job, int $availableAt): bool
    {
        if (!$this->holds($job)) {
            return false;
        }
        $this->jobs[$job->id] = [...$this->jobs[$job->id], 'availableAt' => $availableAt, 'reservedUntil' => null, 'token' => null];

        return true;
    }

    public function requeue(ReservedJob $job, int $availableAt, ?string $payload = null): bool
    {
        if (!$this->holds($job)) {
            return false;
        }
        $held = $this->jobs[$job->id];
        $this->jobs[$job->id] = [
            ...$held,
            'payload' => $payload ?? $held['payload'],
            'availableAt' => $availableAt,
            'attempts' => max(0, $held['attempts'] - 1),
            'reservedUntil' => null,
            'token' => null,
        ];

        return true;
    }

    /** How many times job $id has been taken and not given back, or null when it is not on the queue. */
    public function attempts(string $id): ?int
    {
        return $this->jobs[$id]['attempts'] ?? null;
    }

    /** Job $id's payload as it is stored now, or null when it is not on the queue. */
    public function payload(string $id): ?string
    {
        return $this->jobs[$id]['payload'] ?? null;
    }

    public function fail(ReservedJob $job, string $error, int $failedAt): bool
    {
        if (!$this->holds($job)) {
            return false;
        }
        unset($this->jobs[$job->id]);
        array_unshift($this->failures, new FailedJob($job->id, $job->queue, $job->payload, $error, $failedAt));

        return true;
    }

    /** When job $id's reservation lapses, or null when it is not held. */
    public function reservedUntil(string $id): ?int
    {
        return $this->jobs[$id]['reservedUntil'] ?? null;
    }

    public function enlistsIn(Connection $connection): bool
    {
        return false;
    }

    public function size(string $queue): int
    {
        return \count(array_filter($this->jobs, static fn (array $job): bool => $job['queue'] === $queue));
    }

    public function failed(): array
    {
        return $this->failures;
    }

    public function retry(string $id, int $now): bool
    {
        foreach ($this->failures as $index => $failure) {
            if ($failure->id === $id) {
                array_splice($this->failures, $index, 1);
                $this->push(new PendingJob($failure->id, $failure->queue, $failure->payload, $now));

                return true;
            }
        }

        return false;
    }

    /** @return list<PendingJob> every job pushed, in order */
    public function pushed(): array
    {
        return $this->pushed;
    }

    /** When job $id is next available, or null when it is not on the queue. */
    public function availableAt(string $id): ?int
    {
        return $this->jobs[$id]['availableAt'] ?? null;
    }

    private function holds(ReservedJob $job): bool
    {
        return ($this->jobs[$job->id]['token'] ?? null) === $job->token;
    }
}
