<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Queue\Queue;

/**
 * `tinvar_queue_depth{queue}`: the jobs waiting or held on each queue, as
 * the queue counts them when the metrics are scraped. A depth that only
 * grows is a worker that has stopped, or too few of them.
 */
final class QueueDepth implements Collector
{
    /** @param list<string> $queues the queues to report, by name */
    public function __construct(
        private readonly Queue $queue,
        private readonly array $queues,
    ) {}

    public function collect(): array
    {
        $samples = [];
        foreach ($this->queues as $name) {
            $samples[] = [
                'name' => 'tinvar_queue_depth',
                'help' => 'Jobs waiting or held on a queue.',
                'labels' => ['queue' => $name],
                'value' => $this->queue->size($name),
            ];
        }

        return $samples;
    }
}
