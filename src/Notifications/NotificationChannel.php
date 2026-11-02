<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

/**
 * A way a notification reaches its user (ADR 0155):
 *
 *   - Mail: an email, through the Mailer -- its suppression list, its
 *     providers, and a one-click unsubscribe that turns this kind off.
 *   - Push: a web push to every browser the user subscribed in the tenant,
 *     through PushNotifications -- VAPID-signed, each subscription held to
 *     the auth epoch it was made at.
 *   - Inbox: an entry in the user's in-app feed, per tenant, heard live by
 *     every page they have open (InboxFeed).
 *
 * A notification sends on each channel whose interface it implements
 * (interface()); a user turns each on or off per kind of notification
 * (NotificationPreferences).
 */
enum NotificationChannel: string
{
    case Mail = 'mail';
    case Push = 'push';
    case Inbox = 'inbox';

    /**
     * The interface a notification implements to send on this channel.
     *
     * @return class-string
     */
    public function interface(): string
    {
        return match ($this) {
            self::Mail => MailNotification::class,
            self::Push => PushNotification::class,
            self::Inbox => InboxNotification::class,
        };
    }

    /**
     * The channels $class sends on, in the enum's order.
     *
     * @return list<self>
     */
    public static function of(string $class): array
    {
        return array_values(array_filter(self::cases(), static fn (self $channel): bool => is_a($class, $channel->interface(), true)));
    }
}
