<?php

declare(strict_types=1);

namespace Tinvar\Queue;

use Tinvar\Orm\Connection;

/**
 * A queue in Redis, for a deployment that runs Redis already and wants its
 * workers off the application's database (ADR 0118).
 *
 * The same guarantees as the database queue, kept by Redis's own atomicity
 * rather than a transaction's:
 *
 *   - **Reservations.** A job is taken by a script that moves it from its
 *     queue's ready set to its reserved set and gives it a new token, in one
 *     step no other worker can interleave with; a reservation lapsed is put
 *     back by the next reserve() on that queue. Every change after it --
 *     extend, delete, release, requeue, fail -- is made only while the job's
 *     token is the caller's, so a worker that ran past its reservation
 *     records nothing over the worker that took the job since.
 *   - **Sealed payloads.** A payload is stored and handed back as it was
 *     pushed; nothing here reads one. The tenant, panel, user and epoch it
 *     runs as are under its MAC, checked by the runner (Envelope).
 *   - **Coordination.** Unique jobs' locks, rate windows and batches are
 *     kept beside the jobs, and atomically() writes everything it is given
 *     together or not at all: its reads WATCH what they read, its writes
 *     wait for one MULTI/EXEC, and a write by anyone else to what it read
 *     -- another worker ending the same batch's job, a lapsed reservation
 *     taken over -- aborts the EXEC, and the work is done again from the
 *     top on what Redis holds now. So a job's delete and what follows it
 *     (Jobs\Continuation) land together or neither does, as they do in a
 *     database's transaction.
 *
 * What it cannot be is the outbox. A push is never part of the
 * application's database transaction (enlistsIn() says no), so the
 * dispatcher waits for the commit before it pushes, and a worker sees a job
 * only once the writes it is about are visible. A process that dies between
 * its commit and its push loses that job: the database queue is the one
 * that cannot disagree with the rows around it.
 *
 * Every key starts with the prefix, which holds a hash tag -- `{tinvar}` by
 * default -- so on a cluster every key is in one slot, where a script and a
 * MULTI may reach them all. Give Redis `maxmemory-policy noeviction`: a
 * queue whose jobs can be evicted loses them. Needs ext-redis; opened on
 * first use when given as a closure.
 */
final class RedisQueue implements Queue, Coordination, Wakes
{
    /** How many times atomically() runs its work again after a conflicting write before it gives up. */
    public const int MAX_RETRIES = 32;

    /**
     * Puts lapsed reservations back on their queue, then takes the first job
     * available on it, as one step. KEYS: none, every key is the prefix's.
     * ARGV: prefix, now, reserved until, token, then each queue in turn.
     */
    private const string RESERVE = <<<'LUA'
        local prefix, now, untilAt, token = ARGV[1], tonumber(ARGV[2]), tonumber(ARGV[3]), ARGV[4]
        for i = 5, #ARGV do
            local queue = ARGV[i]
            local ready = prefix .. 'ready:' .. queue
            local reserved = prefix .. 'reserved:' .. queue
            for _, member in ipairs(redis.call('ZRANGEBYSCORE', reserved, '-inf', now, 'LIMIT', 0, 100)) do
                local key = prefix .. 'job:' .. string.sub(member, 18)
                redis.call('ZREM', reserved, member)
                local available = redis.call('HGET', key, 'available_at')
                if available then
                    redis.call('ZADD', ready, tonumber(available), member)
                end
            end
            local first = redis.call('ZRANGEBYSCORE', ready, '-inf', now, 'LIMIT', 0, 1)[1]
            if first then
                local id = string.sub(first, 18)
                local key = prefix .. 'job:' .. id
                local attempts = redis.call('HINCRBY', key, 'attempts', 1)
                redis.call('HSET', key, 'reserved_until', untilAt, 'token', token)
                redis.call('ZREM', ready, first)
                redis.call('ZADD', reserved, untilAt, first)
                return {id, queue, redis.call('HGET', key, 'payload'), attempts}
            end
        end
        return false
        LUA;

    /**
     * What extend(), release() and requeue() do to a job whose token is the
     * caller's: ARGV prefix, id, token, what to do, then its values.
     */
    private const string HOLDER = <<<'LUA'
        local prefix, id, token, action = ARGV[1], ARGV[2], ARGV[3], ARGV[4]
        local key = prefix .. 'job:' .. id
        local fields = redis.call('HMGET', key, 'token', 'queue', 'member', 'attempts')
        if not fields[1] or fields[1] == '' or fields[1] ~= token then
            return 0
        end
        local ready = prefix .. 'ready:' .. fields[2]
        local reserved = prefix .. 'reserved:' .. fields[2]
        local member = fields[3]
        if action == 'extend' then
            redis.call('HSET', key, 'reserved_until', tonumber(ARGV[5]))
            redis.call('ZREM', ready, member)
            redis.call('ZADD', reserved, tonumber(ARGV[5]), member)
            return 1
        end
        redis.call('HSET', key, 'available_at', tonumber(ARGV[5]), 'reserved_until', '', 'token', '')
        if action == 'requeue' then
            local attempts = tonumber(fields[4]) or 0
            redis.call('HSET', key, 'attempts', attempts > 0 and attempts - 1 or 0)
            if ARGV[6] == '1' then
                redis.call('HSET', key, 'payload', ARGV[7])
            end
        end
        redis.call('ZREM', reserved, member)
        redis.call('ZADD', ready, tonumber(ARGV[5]), member)
        return 1
        LUA;

    /**
     * A start of a rate: ARGV prefix, key, runs, per, now, sliding, a
     * start's unique member. 0 when taken; else the seconds to wait.
     */
    private const string SLOT = <<<'LUA'
        local prefix, key, runs, per, now = ARGV[1], ARGV[2], tonumber(ARGV[3]), tonumber(ARGV[4]), tonumber(ARGV[5])
        if ARGV[6] == '1' then
            local starts = prefix .. 'starts:' .. key
            redis.call('ZREMRANGEBYSCORE', starts, '-inf', now - per)
            if redis.call('ZCARD', starts) < runs then
                redis.call('ZADD', starts, now, ARGV[7])
                redis.call('EXPIRE', starts, per + 1)
                return 0
            end
            local oldest = tonumber(redis.call('ZRANGE', starts, 0, 0, 'WITHSCORES')[2])
            return math.max(1, oldest + per - now)
        end
        local rate = prefix .. 'rate:' .. key
        local window = now - now % per
        local fields = redis.call('HMGET', rate, 'window', 'used')
        local current, used = tonumber(fields[1]), tonumber(fields[2]) or 0
        if not current or current < window then
            redis.call('HSET', rate, 'window', window, 'used', 1)
            redis.call('EXPIRE', rate, window + per + 1 - now)
            return 0
        end
        if current == window and used < runs then
            redis.call('HINCRBY', rate, 'used', 1)
            return 0
        end
        return math.max(1, window + per - now)
        LUA;

    /** Gives a start back: ARGV prefix, key, per, now, sliding. */
    private const string UNSLOT = <<<'LUA'
        local prefix, key, per, now = ARGV[1], ARGV[2], tonumber(ARGV[3]), tonumber(ARGV[4])
        if ARGV[5] == '1' then
            local starts = prefix .. 'starts:' .. key
            local last = redis.call('ZRANGEBYSCORE', starts, now, now)
            if #last > 0 then
                redis.call('ZREM', starts, last[#last])
            end
            return 0
        end
        local rate = prefix .. 'rate:' .. key
        local fields = redis.call('HMGET', rate, 'window', 'used')
        if tonumber(fields[1]) == now - now % per and (tonumber(fields[2]) or 0) > 0 then
            redis.call('HINCRBY', rate, 'used', -1)
        end
        return 0
        LUA;

    /**
     * Makes a waiting job available now, if no worker holds it: ARGV
     * prefix, id, now.
     */
    private const string WAKE = <<<'LUA'
        local prefix, id, now = ARGV[1], ARGV[2], tonumber(ARGV[3])
        local key = prefix .. 'job:' .. id
        local fields = redis.call('HMGET', key, 'queue', 'member', 'available_at', 'token', 'reserved_until')
        if not fields[1] or tonumber(fields[3]) <= now then
            return 0
        end
        if fields[4] and fields[4] ~= '' and (tonumber(fields[5]) or 0) > now then
            return 0
        end
        redis.call('HSET', key, 'available_at', now)
        if redis.call('ZSCORE', prefix .. 'ready:' .. fields[1], fields[2]) then
            redis.call('ZADD', prefix .. 'ready:' .. fields[1], now, fields[2])
        end
        return 1
        LUA;

    /** Forgets batches finished before ARGV[3] and locks lapsed by ARGV[2]: ARGV prefix first. */
    private const string PRUNE = <<<'LUA'
        local prefix, now, before = ARGV[1], tonumber(ARGV[2]), tonumber(ARGV[3])
        local count = 0
        local finished = prefix .. 'batches:finished'
        for _, id in ipairs(redis.call('ZRANGEBYSCORE', finished, '-inf', '(' .. before)) do
            redis.call('DEL', prefix .. 'batch:' .. id, prefix .. 'batch-ends:' .. id)
            redis.call('ZREM', finished, id)
            count = count + 1
        end
        local locks = prefix .. 'locks'
        for _, key in ipairs(redis.call('ZRANGEBYSCORE', locks, '-inf', now)) do
            redis.call('DEL', prefix .. 'lock:' .. key)
            redis.call('ZREM', locks, key)
            count = count + 1
        end
        return count
        LUA;

    /** The last enqueue time this process wrote, so its own pushes keep their order within a microsecond. */
    private static int $lastQueuedAt = 0;

    private ?\Redis $redis = null;

    /** Whether an atomically() is in progress, collecting what its work reads and writes. */
    private bool $inUnit = false;

    /** @var array<string, array<string, string>|null> the hashes the unit has read, under WATCH, or written */
    private array $hashes = [];

    /** @var array<string, bool> set memberships the unit has read, under WATCH, or written, by set and member */
    private array $members = [];

    /** @var list<\Closure(\Redis): void> the writes waiting for the unit's EXEC */
    private array $writes = [];

    /**
     * @param \Redis|(\Closure(): \Redis) $connection a connected client, or what connects one
     * @param string $prefix every key's beginning: set apart from anything else kept in the
     *        same Redis, and holding a hash tag so a cluster keeps every key in one slot
     */
    public function __construct(
        private readonly \Redis|\Closure $connection,
        private readonly string $prefix = '{tinvar}:queue:',
    ) {
        if (preg_match('/^[\x21-\x7E]{1,64}\z/', $prefix) !== 1) {
            throw new \InvalidArgumentException('A queue\'s prefix is printable ASCII without spaces, up to 64.');
        }
    }

    /** Whether ext-redis is loaded. */
    public static function available(): bool
    {
        return \extension_loaded('redis');
    }

    public function push(PendingJob $job): void
    {
        self::assertName('job id', $job->id);
        self::assertName('queue', $job->queue);
        $this->atomically(function () use ($job): void {
            $key = $this->key('job:', $job->id);
            if ($this->hash($key) !== null) {
                throw new \RuntimeException("A job with the id '{$job->id}' is queued already.");
            }
            $member = \sprintf('%016d:%s', self::queuedAt(), $job->id);
            $this->stage($key, [
                'queue' => $job->queue,
                'payload' => $job->payload,
                'attempts' => '0',
                'available_at' => (string) $job->availableAt,
                'reserved_until' => '',
                'token' => '',
                'member' => $member,
            ], static function (\Redis $redis) use ($key, $job, $member): void {
                $redis->del($key);
                $redis->hMSet($key, [
                    'queue' => $job->queue,
                    'payload' => $job->payload,
                    'attempts' => 0,
                    'available_at' => $job->availableAt,
                    'reserved_until' => '',
                    'token' => '',
                    'member' => $member,
                ]);
            });
            $ready = $this->key('ready:', $job->queue);
            $this->write(static function (\Redis $redis) use ($ready, $job, $member): void {
                $redis->zAdd($ready, $job->availableAt, $member);
            });
        });
    }

    public function reserve(array $queues, int $now, int $reserveFor): ?ReservedJob
    {
        $this->outsideUnit('reserve()');
        if ($queues === []) {
            return null;
        }
        foreach ($queues as $queue) {
            self::assertName('queue', $queue);
        }

        $token = bin2hex(random_bytes(16));
        $taken = $this->script(self::RESERVE, [$this->prefix, (string) $now, (string) ($now + $reserveFor), $token, ...$queues]);
        if (!\is_array($taken) || \count($taken) !== 4) {
            return null;
        }
        [$id, $queue, $payload, $attempts] = $taken;

        return new ReservedJob((string) $id, (string) $queue, (string) $payload, (int) $attempts, $token);
    }

    public function extend(ReservedJob $job, int $until): bool
    {
        return $this->holder($job, 'extend', [(string) $until]);
    }

    public function delete(ReservedJob $job): bool
    {
        return $this->atomically(function () use ($job): bool {
            $held = $this->held($job);
            if ($held === null) {
                return false;
            }
            $this->forget($job->id, $held);

            return true;
        });
    }

    public function release(ReservedJob $job, int $availableAt): bool
    {
        return $this->holder($job, 'release', [(string) $availableAt]);
    }

    public function requeue(ReservedJob $job, int $availableAt, ?string $payload = null): bool
    {
        return $this->holder($job, 'requeue', [(string) $availableAt, $payload === null ? '0' : '1', $payload ?? '']);
    }

    public function fail(ReservedJob $job, string $error, int $failedAt): bool
    {
        return $this->atomically(function () use ($job, $error, $failedAt): bool {
            // Only the holder records the failure, as only the holder deletes.
            $held = $this->held($job);
            if ($held === null) {
                return false;
            }
            $this->forget($job->id, $held);
            $failed = $this->key('failed:', $job->id);
            $index = $this->key('failed');
            $this->write(static function (\Redis $redis) use ($failed, $index, $job, $held, $error, $failedAt): void {
                $redis->hMSet($failed, ['queue' => $held['queue'] ?? '', 'payload' => $held['payload'] ?? '', 'error' => $error, 'failed_at' => $failedAt]);
                $redis->zAdd($index, $failedAt, $job->id);
            });

            return true;
        });
    }

    public function wake(string $id, int $now): bool
    {
        $this->outsideUnit('wake()');
        self::assertName('job id', $id);

        return (int) $this->script(self::WAKE, [$this->prefix, $id, (string) $now]) === 1;
    }

    public function enlistsIn(Connection $connection): bool
    {
        return false;
    }

    public function size(string $queue): int
    {
        $redis = $this->redis();

        return (int) $redis->zCard($this->key('ready:', $queue)) + (int) $redis->zCard($this->key('reserved:', $queue));
    }

    public function failed(): array
    {
        $redis = $this->redis();
        $ids = $redis->zRevRange($this->key('failed'), 0, -1);
        $failed = [];
        foreach (\is_array($ids) ? $ids : [] as $id) {
            $row = $redis->hGetAll($this->key('failed:', (string) $id));
            if (!\is_array($row) || $row === []) {
                continue;
            }
            $failed[] = new FailedJob((string) $id, (string) ($row['queue'] ?? ''), (string) ($row['payload'] ?? ''), (string) ($row['error'] ?? ''), (int) ($row['failed_at'] ?? 0));
        }

        return $failed;
    }

    public function retry(string $id, int $now): bool
    {
        return $this->atomically(function () use ($id, $now): bool {
            $key = $this->key('failed:', $id);
            $row = $this->hash($key);
            if ($row === null) {
                return false;
            }
            $index = $this->key('failed');
            $this->stage($key, null, static function (\Redis $redis) use ($key, $index, $id): void {
                $redis->del($key);
                $redis->zRem($index, $id);
            });
            $this->push(new PendingJob($id, $row['queue'] ?? '', $row['payload'] ?? '', $now));

            return true;
        });
    }

    public function lock(string $key, string $job, int $until, int $now): ?string
    {
        return $this->atomically(function () use ($key, $job, $until, $now): ?string {
            $lock = $this->key('lock:', $key);
            $held = $this->hash($lock);
            if ($held !== null && (int) ($held['until'] ?? 0) > $now) {
                $holder = $held['job'] ?? '';

                return $holder === $job ? null : $holder;
            }
            $index = $this->key('locks');
            // Redis forgets it a minute past its lapse, worked out from this
            // server's clock as a relative expiry, as the throttle store's.
            $ttl = max(1, $until - $now) + 60;
            $this->stage($lock, ['job' => $job, 'until' => (string) $until], static function (\Redis $redis) use ($lock, $index, $key, $job, $until, $ttl): void {
                $redis->hMSet($lock, ['job' => $job, 'until' => $until]);
                $redis->expire($lock, $ttl);
                $redis->zAdd($index, $until, $key);
            });

            return null;
        });
    }

    public function unlock(string $key, string $job): bool
    {
        return $this->atomically(function () use ($key, $job): bool {
            $lock = $this->key('lock:', $key);
            $held = $this->hash($lock);
            if ($held === null || ($held['job'] ?? null) !== $job) {
                return false;
            }
            $index = $this->key('locks');
            $this->stage($lock, null, static function (\Redis $redis) use ($lock, $index, $key): void {
                $redis->del($lock);
                $redis->zRem($index, $key);
            });

            return true;
        });
    }

    public function slot(string $key, int $runs, int $per, int $now, bool $sliding = false): int
    {
        $this->outsideUnit('slot()');

        return (int) $this->script(self::SLOT, [$this->prefix, $key, (string) $runs, (string) $per, (string) $now, $sliding ? '1' : '0', $now . ':' . bin2hex(random_bytes(8))]);
    }

    public function unslot(string $key, int $per, int $now, bool $sliding = false): void
    {
        $this->outsideUnit('unslot()');
        $this->script(self::UNSLOT, [$this->prefix, $key, (string) $per, (string) $now, $sliding ? '1' : '0']);
    }

    public function addBatch(BatchRecord $batch): void
    {
        $this->atomically(function () use ($batch): void {
            $key = $this->key('batch:', $batch->id);
            if ($this->hash($key) !== null) {
                throw new \RuntimeException("A batch with the id '{$batch->id}' is kept already.");
            }
            $this->putBatch($batch);
        });
    }

    public function batch(string $id): ?BatchRecord
    {
        $row = $this->inUnit ? $this->hash($this->key('batch:', $id)) : self::row($this->redis()->hGetAll($this->key('batch:', $id)));

        return $row === null ? null : self::batchFrom($id, $row);
    }

    public function addToBatch(string $id, int $jobs): bool
    {
        return $this->atomically(function () use ($id, $jobs): bool {
            $batch = $this->batch($id);
            if ($jobs < 1 || $batch === null || $batch->finishedAt !== null || $batch->cancelledAt !== null || $batch->pending === 0) {
                return false;
            }
            $this->putBatch($batch->with(['total' => $batch->total + $jobs, 'pending' => $batch->pending + $jobs]));

            return true;
        });
    }

    public function batchJobDone(string $id, string $job, int $now): ?BatchStep
    {
        return $this->atomically(function () use ($id, $job, $now): ?BatchStep {
            $batch = $this->batch($id);
            if ($batch === null) {
                return null;
            }
            if (!$this->firstEnd($id, $job)) {
                return new BatchStep($batch, false, false);
            }

            return $this->step($batch->with(['pending' => max(0, $batch->pending - 1)]), $now, false);
        });
    }

    public function batchJobFailed(string $id, string $job, int $now): ?BatchStep
    {
        return $this->atomically(function () use ($id, $job, $now): ?BatchStep {
            $batch = $this->batch($id);
            if ($batch === null) {
                return null;
            }
            if (!$this->firstEnd($id, $job)) {
                return new BatchStep($batch, false, false);
            }
            if ($batch->pending === 0) {
                return $this->step($batch, $now, false);
            }
            $batch = $batch->with(['pending' => $batch->pending - 1, 'failed' => $batch->failed + 1]);
            // The first failure cancels a batch that allows none.
            if (!$batch->allowFailures && $batch->cancelledAt === null) {
                $batch = $batch->with(['cancelledAt' => $now]);
            }

            return $this->step($batch, $now, $batch->failed === 1);
        });
    }

    public function prune(int $now, int $finishedBefore): int
    {
        $this->outsideUnit('prune()');

        return (int) $this->script(self::PRUNE, [$this->prefix, (string) $now, (string) $finishedBefore]);
    }

    /**
     * Runs $work as one unit: every key it reads is watched, every write
     * waits for one MULTI/EXEC at its end, and a write by anyone else to a
     * key it read aborts the EXEC -- then $work runs again from the top, on
     * what Redis holds now, up to MAX_RETRIES times. Inside a unit already,
     * $work is part of it.
     *
     * So $work must be the queue's to repeat: what it does outside Redis
     * is done again on a retry. Everything the framework runs in one --
     * a unique job's lock and push, a batch and its jobs, a job's end and
     * what follows it -- is.
     */
    public function atomically(\Closure $work): mixed
    {
        if ($this->inUnit) {
            return $work();
        }

        $redis = $this->redis();
        for ($try = 0; $try < self::MAX_RETRIES; $try++) {
            $this->inUnit = true;
            $this->hashes = $this->members = $this->writes = [];
            try {
                $result = $work();
                $writes = $this->writes;
            } catch (\Throwable $e) {
                $redis->unwatch();

                throw $e;
            } finally {
                $this->inUnit = false;
                $this->hashes = $this->members = $this->writes = [];
            }

            if ($writes === []) {
                $redis->unwatch();

                return $result;
            }

            $redis->multi();
            foreach ($writes as $write) {
                $write($redis);
            }
            // An array of replies when it ran; false when a watched key was
            // written by another client first, and nothing ran.
            if (\is_array($redis->exec())) {
                return $result;
            }
        }

        throw new \RuntimeException('Redis kept changing what the queue read; nothing was written after ' . self::MAX_RETRIES . ' tries.');
    }

    /**
     * The job's stored fields, while $job's token is the one it holds; null
     * when it is no longer its holder's.
     *
     * @return array<string, string>|null
     */
    private function held(ReservedJob $job): ?array
    {
        $row = $this->hash($this->key('job:', $job->id));
        $token = $row['token'] ?? '';

        return $row !== null && $token !== '' && hash_equals($token, $job->token) ? $row : null;
    }

    /**
     * A job off its queue for good, in the unit: its record and its place in
     * either set.
     *
     * @param array<string, string> $held
     */
    private function forget(string $id, array $held): void
    {
        $key = $this->key('job:', $id);
        $ready = $this->key('ready:', $held['queue'] ?? '');
        $reserved = $this->key('reserved:', $held['queue'] ?? '');
        $member = $held['member'] ?? '';
        $this->stage($key, null, static function (\Redis $redis) use ($key, $ready, $reserved, $member): void {
            $redis->del($key);
            $redis->zRem($ready, $member);
            $redis->zRem($reserved, $member);
        });
    }

    /**
     * Whether $job's end is the first counted for batch $id: counted now,
     * in the unit, if so.
     */
    private function firstEnd(string $id, string $job): bool
    {
        $ends = $this->key('batch-ends:', $id);
        $seen = $this->isMember($ends, $job);
        if ($seen) {
            return false;
        }
        $this->members[$ends . "\0" . $job] = true;
        $this->write(static function (\Redis $redis) use ($ends, $job): void {
            $redis->sAdd($ends, $job);
        });

        return true;
    }

    /** The batch after a count moved, finished if that was its last pending job. */
    private function step(BatchRecord $batch, int $now, bool $firstFailure): BatchStep
    {
        $finished = $batch->pending === 0 && $batch->finishedAt === null;
        if ($finished) {
            $batch = $batch->with(['finishedAt' => $now]);
        }
        $this->putBatch($batch);

        return new BatchStep($batch, $finished, $firstFailure);
    }

    private function putBatch(BatchRecord $batch): void
    {
        $key = $this->key('batch:', $batch->id);
        $fields = [
            'tenant' => $batch->tenant,
            'panel' => $batch->panel,
            'user' => $batch->user,
            'total' => (string) $batch->total,
            'pending' => (string) $batch->pending,
            'failed' => (string) $batch->failed,
            'allow_failures' => $batch->allowFailures ? '1' : '0',
            'callbacks' => $batch->callbacksJson(),
            'created_at' => (string) $batch->createdAt,
            'cancelled_at' => $batch->cancelledAt === null ? '' : (string) $batch->cancelledAt,
            'finished_at' => $batch->finishedAt === null ? '' : (string) $batch->finishedAt,
        ];
        $finished = $this->key('batches:finished');
        $this->stage($key, $fields, static function (\Redis $redis) use ($key, $fields, $finished, $batch): void {
            $redis->hMSet($key, $fields);
            if ($batch->finishedAt !== null) {
                $redis->zAdd($finished, $batch->finishedAt, $batch->id);
            }
        });
    }

    /** @param array<string, string> $row */
    private static function batchFrom(string $id, array $row): BatchRecord
    {
        $time = static fn (string $field): ?int => ($row[$field] ?? '') === '' ? null : (int) $row[$field];

        return new BatchRecord(
            id: $id,
            tenant: $row['tenant'] ?? '',
            panel: $row['panel'] ?? '',
            user: $row['user'] ?? '',
            total: (int) ($row['total'] ?? 0),
            pending: (int) ($row['pending'] ?? 0),
            failed: (int) ($row['failed'] ?? 0),
            allowFailures: ($row['allow_failures'] ?? '0') === '1',
            createdAt: (int) ($row['created_at'] ?? 0),
            cancelledAt: $time('cancelled_at'),
            finishedAt: $time('finished_at'),
            callbacks: BatchRecord::callbacksFrom($row['callbacks'] ?? null),
        );
    }

    /**
     * What extend(), release() and requeue() do: one script, acting only for
     * the holder of the job's current token. The queue it is on is read from
     * the job's own record, never from the caller -- a hand-back knows the
     * job's id and token and nothing else (HeldJob).
     *
     * @param list<string> $values
     */
    private function holder(ReservedJob $job, string $action, array $values): bool
    {
        $this->outsideUnit("{$action}()");

        return (int) $this->script(self::HOLDER, [$this->prefix, $job->id, $job->token, $action, ...$values]) === 1;
    }

    /**
     * A hash as the unit sees it: what it wrote there, or else what Redis
     * holds now, read under WATCH.
     *
     * @return array<string, string>|null null where there is none
     */
    private function hash(string $key): ?array
    {
        \assert($this->inUnit);
        if (!\array_key_exists($key, $this->hashes)) {
            $redis = $this->redis();
            $redis->watch($key);
            $this->hashes[$key] = self::row($redis->hGetAll($key));
        }

        return $this->hashes[$key];
    }

    private function isMember(string $set, string $member): bool
    {
        \assert($this->inUnit);
        $at = $set . "\0" . $member;
        if (!\array_key_exists($at, $this->members)) {
            $redis = $this->redis();
            $redis->watch($set);
            $this->members[$at] = (bool) $redis->sIsMember($set, $member);
        }

        return $this->members[$at];
    }

    /**
     * A write to a hash, seen by the unit's later reads at once, and made in
     * its EXEC.
     *
     * @param array<string, string>|null $fields the hash as it will be; null for gone
     * @param \Closure(\Redis): void $write
     */
    private function stage(string $key, ?array $fields, \Closure $write): void
    {
        \assert($this->inUnit);
        $this->hashes[$key] = $fields;
        $this->write($write);
    }

    /** @param \Closure(\Redis): void $write */
    private function write(\Closure $write): void
    {
        \assert($this->inUnit);
        $this->writes[] = $write;
    }

    /**
     * Runs one of the scripts above. Its keys are all under the prefix,
     * named by the script from it, so each runs on one slot's keys.
     *
     * Redis's EVAL runs only the constant scripts of this class: ids,
     * tokens, payloads and counts travel as its ARGV and are never part of
     * a script.
     *
     * @param list<string> $arguments
     */
    private function script(string $script, array $arguments): mixed
    {
        $redis = $this->redis();
        $result = $redis->eval($script, $arguments, 0);
        $error = $redis->getLastError();
        if ($error !== null) {
            $redis->clearLastError();

            throw new \RuntimeException('Redis refused a queue script: ' . $error);
        }

        return $result;
    }

    /** A script runs at once, so it cannot be part of a unit's writes; asked for inside one, it is refused. */
    private function outsideUnit(string $what): void
    {
        if ($this->inUnit) {
            throw new \LogicException("A Redis queue's {$what} runs on its own, and cannot be part of atomically().");
        }
    }

    private function key(string $kind, string $name = ''): string
    {
        return $this->prefix . $kind . $name;
    }

    /** @return array<string, string>|null */
    private static function row(mixed $hash): ?array
    {
        if (!\is_array($hash) || $hash === []) {
            return null;
        }
        $row = [];
        foreach ($hash as $field => $value) {
            $row[(string) $field] = (string) $value;
        }

        return $row;
    }

    /**
     * A name that becomes part of a key: printable, without the colon a key
     * is split on, and short. A job id is the dispatcher's UUID, a queue a
     * word an attribute names.
     */
    private static function assertName(string $what, string $name): void
    {
        if (preg_match('/^[A-Za-z0-9._\-]{1,128}\z/', $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not a {$what} this queue keeps: letters, digits, '.', '_' and '-', up to 128.");
        }
    }

    /** When a job was queued, in microseconds: its order among the jobs available in the same second. */
    private static function queuedAt(): int
    {
        return self::$lastQueuedAt = max(self::$lastQueuedAt + 1, (int) (microtime(true) * 1_000_000));
    }

    private function redis(): \Redis
    {
        return $this->redis ??= $this->connection instanceof \Redis ? $this->connection : ($this->connection)();
    }
}
