<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Auth\Authenticatable;
use Tinvar\Jobs\Queueable;
use Tinvar\Orm\Orm;

/**
 * Sends notifications (ADR 0150): one call, every channel the user gets.
 *
 *     $notifier->send($customer, new OrderShipped($order));
 *
 * Queued: one SendNotification job, sealed with the tenant in force, which
 * waits for the transaction around the call to commit (Orm::afterCommit),
 * so an order whose write is rolled back tells nobody it shipped. The
 * worker reads the user's preferences and sends on each channel they get.
 * sendNow() fans out in this process instead, still queueing the email
 * and the pushes on their own queues.
 *
 * Refused for a class that is no registered #[Notification], and inside
 * acrossTenants() or withinSubtree(), where no one tenant is the one the
 * user is told in.
 */
final readonly class Notifier
{
    public function __construct(
        private Notifications $notifications,
        private Orm $orm,
        private NotificationDelivery $delivery,
    ) {}

    /**
     * @param Queueable $notification a #[Notification] instance
     * @return string the job's id
     */
    public function send(Authenticatable $user, Queueable $notification, int $delay = 0): string
    {
        $this->assertSendable($notification);

        return $this->notifications->jobs->dispatch(new SendNotification($user->authIdentifier(), $notification), $delay);
    }

    /**
     * Sends $notification to each of $users, a job each.
     *
     * @param iterable<Authenticatable> $users
     * @return list<string> the jobs' ids
     */
    public function sendToEach(iterable $users, Queueable $notification): array
    {
        $ids = [];
        foreach ($users as $user) {
            $ids[] = $this->send($user, $notification);
        }

        return $ids;
    }

    /**
     * Fans $notification out now, in this process and its transaction.
     *
     * @return list<NotificationChannel> the channels it was sent on
     */
    public function sendNow(Authenticatable $user, Queueable $notification): array
    {
        $this->assertSendable($notification);

        return $this->delivery->deliver($user->authIdentifier(), $notification);
    }

    private function assertSendable(Queueable $notification): void
    {
        if ($this->notifications->registry->forClass($notification::class) === null) {
            throw new \LogicException(
                $notification::class . ' is no #[Notification] the application registered. Give it #[Notification(\'type\', '
                . 'label: \'...\')] in the application\'s namespace, where discovery finds it.',
            );
        }
        if ($this->orm->isWidened()) {
            throw new \LogicException(
                'A notification is sent in the tenant its user is told in, and this was inside acrossTenants() or '
                . 'withinSubtree(): send it inside actAs() for that tenant.',
            );
        }
    }
}
