<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * Work to do later, on a worker.
 *
 *     #[Queue('mail', tries: 5)]
 *     final readonly class SendReceipt implements Job
 *     {
 *         public function __construct(public Order $order) {}
 *
 *         public function handle(Mailer $mailer): void { ... }
 *     }
 *
 * `handle()` takes its services by type, from the container; the job's own
 * data is its constructor's, which travels in the payload (Queueable). A
 * model travels as its class and id, and is read again when the job runs,
 * in the tenant the job was dispatched in. An optional `failed(\Throwable)`
 * runs once the last try has failed.
 *
 * No `handle` in the interface: its parameters are each job's own.
 */
interface Job extends Queueable {}
