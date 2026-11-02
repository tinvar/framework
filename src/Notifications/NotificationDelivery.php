<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Auth\Authenticatable;
use Tinvar\Jobs\Queueable;
use Tinvar\Mail\SendMail;
use Tinvar\Mail\SentMessage;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;

/**
 * Sends one notification to one user, on the channels they get for it
 * (ADR 0154): what SendNotification runs on the worker, in the tenant the
 * notification was sent in.
 *
 * The user is found again by id, and a user gone since sends nothing. Their
 * preferences are read now, not when it was sent. Then, in one
 * transaction: the inbox entry written (and announced once it commits),
 * the email queued on the mail queue (SendNotificationMail, which reads
 * the preference again and builds the email there), and each push queued
 * through PushNotifications -- whose own jobs run only after the commit,
 * or in it on a queue sharing the connection. So a try that fails part way
 * leaves nothing behind for its retry to send twice.
 */
final readonly class NotificationDelivery
{
    public function __construct(
        private Notifications $notifications,
        private InboxFeed $feed,
        private Orm $orm,
    ) {}

    /**
     * @return list<NotificationChannel> the channels it was sent on
     */
    public function deliver(string $userId, Queueable $notification): array
    {
        $compiled = $this->compiled($notification);
        $user = $this->notifications->users->find($userId);
        if ($user === null) {
            return [];
        }
        $chosen = $this->notifications->preferences->chosen(TenantClaim::current($this->orm), $userId)[$compiled->type] ?? [];
        $channels = $compiled->channelsFor($chosen);

        return $this->orm->transaction(function () use ($channels, $notification, $user, $userId, $compiled): array {
            $sent = [];
            foreach ($channels as $channel) {
                if ($this->sendOn($channel, $notification, $user, $userId, $compiled->type)) {
                    $sent[] = $channel;
                }
            }

            return $sent;
        });
    }

    /** Sends $notification on one channel; whether anything went. */
    private function sendOn(NotificationChannel $channel, Queueable $notification, Authenticatable $user, string $userId, string $type): bool
    {
        if ($channel === NotificationChannel::Inbox && $notification instanceof InboxNotification) {
            $message = $notification->inbox($user);
            if ($message !== null) {
                $this->feed->add($userId, $type, $message);
            }

            return $message !== null;
        }
        if ($channel === NotificationChannel::Mail && $notification instanceof MailNotification) {
            $this->notifications->jobs->dispatch(new SendNotificationMail($userId, $notification));

            return true;
        }
        if ($channel === NotificationChannel::Push && $notification instanceof PushNotification && $this->notifications->push !== null) {
            $payload = $notification->push($user);
            if ($payload !== null) {
                $this->notifications->push->send($user, $payload);
            }

            return $payload !== null;
        }

        return false;
    }

    /**
     * Sends a notification's email to one user now, if they still get its
     * mail: what SendNotificationMail runs on the mail queue. Null when
     * nothing was sent -- the user is gone, turned this kind's mail off
     * since, or mail() answered null.
     *
     * @throws \Tinvar\Jobs\JobRefused for a message a provider refused for good
     */
    public function mail(string $userId, Queueable $notification): ?SentMessage
    {
        $compiled = $this->compiled($notification);
        $mailer = $this->notifications->mailer;
        $user = $this->notifications->users->find($userId);
        if (!$notification instanceof MailNotification || $mailer === null || $user === null) {
            return null;
        }
        // Asked again here: an unsubscribe between the fan-out and this try
        // is honoured.
        $chosen = $this->notifications->preferences->chosen(TenantClaim::current($this->orm), $userId)[$compiled->type] ?? [];
        if (!\in_array(NotificationChannel::Mail, $compiled->channelsFor($chosen), true)) {
            return null;
        }

        // Made here, on the worker, and sent at once: never at rest in a
        // job's payload.
        $links = $this->notifications->unsubscribeLinks;
        $tenant = $this->orm->tenant();
        $email = $notification->mail($user, $links?->url($compiled->unsubscribeList(), $userId, $tenant));
        if ($email === null) {
            return null;
        }
        if ($email->unsubscribe === null && $links !== null) {
            $email = $email->withUnsubscribe($links->for($compiled->unsubscribeList(), $userId, $tenant));
        }

        return SendMail::attempt(static fn (): SentMessage => $mailer->deliver(new NotificationEmail($email)));
    }

    private function compiled(Queueable $notification): CompiledNotification
    {
        return $this->notifications->registry->forClass($notification::class)
            ?? throw new \LogicException(
                $notification::class . ' is no #[Notification] the application registered. Give it #[Notification(\'type\', '
                . 'label: \'...\')] in the application\'s namespace, where discovery finds it.',
            );
    }
}
