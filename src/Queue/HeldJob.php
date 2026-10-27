<?php

declare(strict_types=1);

namespace Tinvar\Queue;

/**
 * The job a worker holds, written where whatever stops the worker can read
 * it: the worker's pid, the job's id and its reservation's token, in a file
 * `tinvar serve` names for it (TINVAR_WORKER_HELD) in serve's own private
 * directory.
 *
 * A job blocked in a read -- a slow server, a lock -- runs no PHP until the
 * read returns: neither the stop's handler nor its alarm can stop it, and a
 * worker killed holding it leaves the job reserved until the reservation
 * lapses, its timeout and RESERVATION_MARGIN later. So serve, once it has
 * killed a worker that would not stop, and made sure it is gone, hands the
 * job back itself (`queue:hand-back`), with the token: a job finished,
 * handed back already or taken by another worker since is left alone.
 */
final class HeldJob
{
    public const string ENV = 'TINVAR_WORKER_HELD';

    /** Records $job as held by process $pid; false when the file could not be written. */
    public static function write(string $path, int $pid, ReservedJob $job): bool
    {
        if (!self::isWritable($job)) {
            return false;
        }

        return @file_put_contents($path, "{$pid} {$job->id} {$job->token}\n", LOCK_EX) !== false;
    }

    /** Nothing held any more. */
    public static function clear(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * What the file says is held, or null for nothing, or anything that is
     * not what write() writes.
     *
     * @return array{pid: int, job: ReservedJob}|null
     */
    public static function read(string $path): ?array
    {
        $text = is_file($path) ? @file_get_contents($path, length: 512) : false;
        if ($text === false || preg_match('/^([1-9]\d{0,9}) ([A-Za-z0-9._-]{1,128}) ([a-f0-9]{16,64})\n\z/', $text, $m) !== 1) {
            return null;
        }

        return ['pid' => (int) $m[1], 'job' => new ReservedJob($m[2], '', '', 0, $m[3])];
    }

    /**
     * Whether process $pid is still running: signalled, and not a zombie,
     * which /proc tells where there is one. A process that is not this
     * one's child is reaped by whoever adopted it.
     *
     * @phpstan-impure
     */
    public static function running(int $pid): bool
    {
        if (!\function_exists('posix_kill') || !posix_kill($pid, 0)) {
            return false;
        }
        $stat = is_readable("/proc/{$pid}/stat") ? @file_get_contents("/proc/{$pid}/stat") : false;
        if ($stat === false) {
            return true;
        }
        $after = strrchr($stat, ')');

        return $after === false || !str_starts_with(ltrim(substr($after, 1)), 'Z');
    }

    private static function isWritable(ReservedJob $job): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,128}\z/', $job->id) === 1 && preg_match('/^[a-f0-9]{16,64}\z/', $job->token) === 1;
    }
}
