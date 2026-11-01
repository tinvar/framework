<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Queue\Coordination;

/**
 * How fast a rate-limited queue's jobs may start: at most `runs` in `per`
 * seconds, counted across every worker on the queue's store (ADR 0100,
 * 0118).
 *
 *     new Console(..., rates: [
 *         'mail' => new Rate(runs: 100, per: 60),
 *         'exports' => Rate::perTenant(runs: 5, per: 3600, sliding: true),
 *     ]);
 *
 * Given by the application where it wires its console, not by each worker
 * started, so no worker started without a flag runs past it.
 *
 * **Fixed or sliding.** A fixed window is the clock's -- the minute a start
 * falls in, not the sixty seconds before it -- so up to twice `runs` can
 * start across a window's edge. A sliding one counts the `per` seconds
 * before each start, so no `per` seconds anywhere hold more than `runs`; it
 * keeps a row a start, where a fixed window keeps one a key
 * (DatabaseQueue::defineSlidingRates()).
 *
 * **Per queue or per tenant.** Per queue, a worker takes a start before it
 * reserves, and passes over a queue whose starts are spent. Per tenant, the
 * tenant is known only once the job's payload has been opened and verified
 * -- the queue never reads one -- so the worker reserves, opens, and takes
 * the start for the tenant the payload seals; a job whose tenant has none
 * left goes back on its queue, its try given back, until one may be free,
 * and the jobs behind it are taken meanwhile. One tenant's flood so waits
 * on its own starts, never another's.
 */
final readonly class Rate
{
    /** A day: past that, a window is not a rate -- and what the queue's prune forgets past. */
    public const int MAX_PER = Coordination::LONGEST_WINDOW;

    public function __construct(
        public int $runs,
        public int $per = 60,
        public bool $sliding = false,
        public bool $perTenant = false,
    ) {
        if ($runs < 1) {
            throw new \InvalidArgumentException('A rate lets at least one job start in its window.');
        }
        if ($per < 1 || $per > self::MAX_PER) {
            throw new \InvalidArgumentException('A rate\'s window is from a second to a day.');
        }
    }

    /** At most $runs starts in $per seconds for each tenant's jobs on the queue. */
    public static function perTenant(int $runs, int $per = 60, bool $sliding = false): self
    {
        return new self($runs, $per, $sliding, perTenant: true);
    }

    /**
     * What the rate counts under on the queue's store: the queue's name, as
     * a rate per queue always has been, or a digest of the queue and the
     * tenant -- so no tenant's id is written beside the counts, and no
     * tenant spends another's.
     */
    public function key(string $queue, string $tenant = ''): string
    {
        if (!$this->perTenant) {
            return $queue;
        }

        return 't:' . substr(hash('sha256', "tinvar rate\0{$queue}\0{$tenant}"), 0, 62);
    }
}
