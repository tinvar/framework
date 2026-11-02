<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\Queueable;

/**
 * One notification to one user, fanned out on the worker (ADR 0155): queued
 * by Notifier::send(), sealed with the tenant it was sent in, after the
 * commit of the transaction that sent it. The notification travels as its
 * class and constructor's arguments -- its models by id, read again here,
 * in that tenant -- and the user by id, found again here.
 */
#[Queue(Notifications::QUEUE, tries: 5, backoff: [10, 60, 300, 900], timeout: 60)]
final readonly class SendNotification implements Job
{
    public function __construct(public string $user, public Queueable $notification) {}

    public function handle(NotificationDelivery $delivery): void
    {
        $delivery->deliver($this->user, $this->notification);
    }
}
