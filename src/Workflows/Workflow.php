<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

use Tinvar\Jobs\Queueable;

/**
 * Work in named steps that outlives the request that started it: each step
 * runs once, on a worker, and what it returned is kept, so the workflow
 * picks up after the last step that finished when a worker dies, a deploy
 * stops it, or it sleeps for an hour.
 *
 *     #[Panel('admin', permission: 'orders.fulfil'), Queue('fulfilment', tries: 5)]
 *     final readonly class FulfilOrder implements Workflow
 *     {
 *         public function __construct(public Order $order) {}
 *
 *         public function run(Steps $steps, Orm $orm): void
 *         {
 *             $steps->step('pack', fn () => $this->pack($orm));
 *             $steps->sleep('collection', 3600);
 *             $steps->step('ship', fn () => $this->ship($orm));
 *         }
 *     }
 *
 * The constructor is its data, as a job's is (Queueable): scalars, enums,
 * dates and models by id, read again in its tenant on every step. `run()`
 * takes Steps and its services by type. An optional `failed(\Throwable)`
 * runs once a step has failed its last try.
 *
 * Started with Workflows::start(), it runs as the tenant, panel, user and
 * auth epoch it was started under, sealed with it and checked before every
 * step: an epoch that moves -- a role taken away, impersonation ended --
 * ends it at the next step. See Steps for how run() is replayed.
 *
 * No `run` in the interface: its parameters are each workflow's own.
 */
interface Workflow extends Queueable {}
