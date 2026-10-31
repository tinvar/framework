<?php

declare(strict_types=1);

/**
 * A queue worker and the build history: what an operator records with
 * `tinvar build:revoke` or `build:floor` is in force on the worker's next
 * job, as it is on the HTTP worker's next request, without a restart.
 *
 * The HTTP Kernel reads the history once a request and forgets it when its
 * scope resets. A worker runs outside the Kernel, with whatever scope it was
 * handed -- here one that knows nothing of the registry -- so a history read
 * by one job was the history every job after it saw, for the life of the
 * process.
 */

namespace Tinvar\Tests\Jobs\BuildHistory;

use Tinvar\Http\BuildHistory;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\BuildStore;
use Tinvar\Jobs\Job;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;

require_once __DIR__ . '/Fixtures/jobs.php';

/** A build history in memory, which an operator's command rewrites. */
final class MemoryBuildStore implements BuildStore
{
    public int $reads = 0;

    public function __construct(private BuildHistory $history) {}

    public function read(): BuildHistory
    {
        $this->reads++;

        return $this->history;
    }

    public function update(\Closure $change): BuildHistory
    {
        return $this->history = $change($this->history);
    }
}

/** Asks the registry whether it still answers for a build, as a job checking a token would. */
final readonly class ChecksBuild implements Job
{
    public function __construct(public string $build) {}

    public function handle(BuildRegistry $builds, Journal $journal): void
    {
        $journal->entries[] = ['build' => $this->build, 'accepted' => $builds->accepts($this->build)];
    }
}

it('sees a build revoked between two jobs, on a worker that read the history before it', function () {
    $store = new MemoryBuildStore(new BuildHistory()->released('b1', time() - 3600)->released('b2', time() - 1800));
    $h = new Harness();
    $h->container->instance(BuildRegistry::class, BuildRegistry::persisted('b2', $store));

    $h->dispatcher->dispatch(new ChecksBuild('b1'));
    $h->work();

    // An operator, from a terminal, while the worker runs on.
    $store->update(static fn (BuildHistory $history): BuildHistory => $history->revoking('b1'));

    $h->dispatcher->dispatch(new ChecksBuild('b1'));
    $h->work();

    expect(array_column($h->journal()->entries, 'accepted'))->toBe([true, false]);
});
