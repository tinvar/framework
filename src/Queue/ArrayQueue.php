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
 * can see it. Its locks, windows and batches (Coordination) are as
 * in-memory, and atomically() runs its work as it is: one process, one
 * thing at a time.
 */
final class ArrayQueue implements Queue, Coordination
{
    /** @var array<string, array{queue: string, payload: string, availableAt: int, attempts: int, reservedUntil: ?int, token: ?string}> */
    private array $jobs = [];

    /** @var list<FailedJob> */
    private array $failures = [];

    /** @var list<PendingJob> every job pushed, in order, for assertions */
    private array $pushed = [];

    /** @var array<string, array{job: string, until: int}> */
    private array $locks = [];

    /** @var array<string, array{window: int, used: int}> */
    private array $rates = [];

    /** @var array<string, list<int>> a sliding window's starts, by key, oldest first */
    private array $starts = [];

    /** @var array<string, BatchRecord> */
    private array $batches = [];

    /** @var array<string, true> batch and job, each counted once */
    private array $ended = [];

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

    public function lock(string $key, string $job, int $until, int $now): ?string
    {
        $held = $this->locks[$key] ?? null;
        if ($held !== null && $held['until'] > $now && $held['job'] !== $job) {
            return $held['job'];
        }
        $this->locks[$key] = ['job' => $job, 'until' => $until];

        return null;
    }

    public function unlock(string $key, string $job): bool
    {
        if (($this->locks[$key]['job'] ?? null) !== $job) {
            return false;
        }
        unset($this->locks[$key]);

        return true;
    }

    /**
     * Who holds lock $key, and until when; null when no one has it.
     *
     * @return array{job: string, until: int}|null
     */
    public function locked(string $key): ?array
    {
        return $this->locks[$key] ?? null;
    }

    public function slot(string $key, int $runs, int $per, int $now, bool $sliding = false): int
    {
        if ($sliding) {
            $starts = array_values(array_filter($this->starts[$key] ?? [], static fn (int $at): bool => $at > $now - $per));
            if (\count($starts) >= $runs) {
                $this->starts[$key] = $starts;

                return max(1, $starts[0] + $per - $now);
            }
            $starts[] = $now;
            $this->starts[$key] = $starts;

            return 0;
        }

        $window = $now - $now % $per;
        $rate = $this->rates[$key] ?? null;
        if ($rate === null || $rate['window'] < $window) {
            $rate = ['window' => $window, 'used' => 0];
        }
        if ($rate['used'] >= $runs) {
            return max(1, $window + $per - $now);
        }
        $this->rates[$key] = ['window' => $window, 'used' => $rate['used'] + 1];

        return 0;
    }

    public function unslot(string $key, int $per, int $now, bool $sliding = false): void
    {
        if ($sliding) {
            $starts = $this->starts[$key] ?? [];
            $at = array_search($now, array_reverse($starts, true), true);
            if ($at !== false) {
                unset($starts[$at]);
                $this->starts[$key] = array_values($starts);
            }

            return;
        }

        $rate = $this->rates[$key] ?? null;
        if ($rate !== null && $rate['window'] === $now - $now % $per && $rate['used'] > 0) {
            $this->rates[$key] = ['window' => $rate['window'], 'used' => $rate['used'] - 1];
        }
    }

    public function addBatch(BatchRecord $batch): void
    {
        $this->batches[$batch->id] = $batch;
    }

    public function batch(string $id): ?BatchRecord
    {
        return $this->batches[$id] ?? null;
    }

    public function addToBatch(string $id, int $jobs): bool
    {
        $batch = $this->batches[$id] ?? null;
        if ($jobs < 1 || $batch === null || $batch->finishedAt !== null || $batch->cancelledAt !== null || $batch->pending === 0) {
            return false;
        }
        $this->batches[$id] = $batch->with(['total' => $batch->total + $jobs, 'pending' => $batch->pending + $jobs]);

        return true;
    }

    public function batchJobDone(string $id, string $job, int $now): ?BatchStep
    {
        $batch = $this->batches[$id] ?? null;
        if ($batch === null) {
            return null;
        }
        if (!$this->firstEnd($id, $job)) {
            return new BatchStep($batch, false, false);
        }
        $batch = $batch->with(['pending' => max(0, $batch->pending - 1)]);

        return $this->step($batch, $now, false);
    }

    public function batchJobFailed(string $id, string $job, int $now): ?BatchStep
    {
        $batch = $this->batches[$id] ?? null;
        if ($batch === null) {
            return null;
        }
        if (!$this->firstEnd($id, $job) || $batch->pending === 0) {
            return new BatchStep($batch, false, false);
        }
        $batch = $batch->with(['pending' => $batch->pending - 1, 'failed' => $batch->failed + 1]);
        if (!$batch->allowFailures && $batch->cancelledAt === null) {
            $batch = $batch->with(['cancelledAt' => $now]);
        }

        return $this->step($batch, $now, $batch->failed === 1);
    }

    private function firstEnd(string $batch, string $job): bool
    {
        $key = "{$batch}\0{$job}";
        if (isset($this->ended[$key])) {
            return false;
        }
        $this->ended[$key] = true;

        return true;
    }

    private function step(BatchRecord $batch, int $now, bool $firstFailure): BatchStep
    {
        $finished = $batch->pending === 0 && $batch->finishedAt === null;
        if ($finished) {
            $batch = $batch->with(['finishedAt' => $now]);
        }
        $this->batches[$batch->id] = $batch;

        return new BatchStep($batch, $finished, $firstFailure);
    }

    public function prune(int $now, int $finishedBefore): int
    {
        $before = \count($this->batches) + \count($this->locks);
        $this->batches = array_filter($this->batches, static fn (BatchRecord $b): bool => $b->finishedAt === null || $b->finishedAt >= $finishedBefore);
        $this->locks = array_filter($this->locks, static fn (array $lock): bool => $lock['until'] > $now);

        return $before - \count($this->batches) - \count($this->locks);
    }

    public function atomically(\Closure $work): mixed
    {
        return $work();
    }

    private function holds(ReservedJob $job): bool
    {
        return ($this->jobs[$job->id]['token'] ?? null) === $job->token;
    }
}
