<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Auth\UserLookup;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\UnsubscribeLinks;
use Tinvar\Push\PushNotifications;

/**
 * What an application supplies for notifications (ADR 0152): its
 * #[Notification] and #[Inbox] classes, where inbox entries and
 * preferences are kept, how jobs are queued and users found again, and the
 * channels' own modules -- the Mailer, PushNotifications and the one-click
 * unsubscribe links mail carries.
 *
 * Handed to the Kernel, which mounts POST /_inbox/{name}, registers each
 * inbox's personal channel, and puts the Notifier, the InboxFeed and the
 * rest in the container. Without it, nothing is mounted.
 *
 * Fails closed when it is built: a notification that sends mail with no
 * Mailer, push with no PushNotifications, or to an inbox the application
 * declares none of, is refused here by name, rather than left to send
 * nothing on its first try.
 */
final readonly class Notifications
{
    /** The queue a notification's fan-out runs on; its mail goes on the mail queue, its pushes on the push queue. */
    public const string QUEUE = 'notifications';

    /** How long an inbox entry is kept: ninety days (PruneNotifications). */
    public const int KEEP_ENTRIES = 7_776_000;

    public function __construct(
        public NotificationRegistry $registry,
        public InboxRegistry $inboxes,
        public InboxStore $inbox,
        public NotificationPreferences $preferences,
        public Dispatcher $jobs,
        public UserLookup $users,
        public ?Mailer $mailer = null,
        public ?PushNotifications $push = null,
        public ?UnsubscribeLinks $unsubscribeLinks = null,
    ) {
        $needs = [
            [NotificationChannel::Mail, $mailer !== null, 'give Notifications the application\'s Mailer'],
            [NotificationChannel::Push, $push !== null, 'give Notifications a PushNotifications, built on the VAPID keys (tinvar push:keys)'],
            [NotificationChannel::Inbox, $inboxes->all() !== [], 'declare an #[Inbox] for users to read it through'],
        ];
        foreach ($needs as [$channel, $met, $remedy]) {
            $sender = $met ? null : $registry->anySends($channel);
            if ($sender !== null) {
                throw new \InvalidArgumentException(
                    "{$sender->where()} sends on {$channel->value}, and nothing here can deliver it: {$remedy}, or take "
                    . (new \ReflectionClass($channel->interface()))->getShortName() . " off {$sender->class}.",
                );
            }
        }
    }
}
