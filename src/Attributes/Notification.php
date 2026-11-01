<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Notifications\NotificationChannel;

/**
 * A kind of notification: something the application tells a user, on the
 * channels its class sends on (ADR 0150).
 *
 *     #[Notification('orders.shipped', label: 'Order shipped', defaults: [NotificationChannel::Mail, NotificationChannel::Inbox])]
 *     final readonly class OrderShipped implements MailNotification, PushNotification, InboxNotification
 *     {
 *         public function __construct(public Order $order) {}
 *         ...
 *     }
 *
 *     $notifier->send($customer, new OrderShipped($order));
 *
 * Found by discovery under the application's namespace, and registered.
 * The channels are the interfaces the class implements -- MailNotification,
 * PushNotification, InboxNotification -- each with the payload for that
 * channel. $type names the kind to the user's preferences, its unsubscribe
 * link and the client: dot-separated lowercase words, unique in the
 * application. $label and $description are what a preferences page shows.
 * $defaults are the channels a user who has chosen nothing for this kind
 * gets; null, every channel it sends on.
 *
 * Its constructor is its data, as a job's is -- models by id, read again
 * on the worker -- since every send is queued (Notifier::send()).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Notification
{
    /** A type: dot-separated lowercase words, short enough to name an unsubscribe list. */
    public const string TYPE = '/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)*\z/';

    public const int MAX_TYPE_BYTES = 56;

    /** @var list<NotificationChannel>|null */
    public ?array $defaults;

    /**
     * @param array<array-key, mixed>|null $defaults the channels a user gets before choosing:
     *        NotificationChannel cases; null for every channel the class sends on
     */
    public function __construct(
        public string $type,
        public string $label,
        ?array $defaults = null,
        public string $description = '',
    ) {
        if (\strlen($type) > self::MAX_TYPE_BYTES || preg_match(self::TYPE, $type) !== 1) {
            throw new \InvalidArgumentException(
                "'{$type}' is not a notification type: dot-separated lowercase words of up to " . self::MAX_TYPE_BYTES
                . " bytes, as in 'orders.shipped'.",
            );
        }
        if (trim($label) === '' || mb_strlen($label) > 100) {
            throw new \InvalidArgumentException("The notification '{$type}' needs a label of 1 to 100 characters: what a preferences page calls it.");
        }
        if ($defaults !== null) {
            foreach ($defaults as $channel) {
                if (!$channel instanceof NotificationChannel) {
                    throw new \InvalidArgumentException("The notification '{$type}' lists a default that is no NotificationChannel case.");
                }
            }
            $defaults = array_values(array_unique($defaults, SORT_REGULAR));
        }
        /** @var list<NotificationChannel>|null $defaults */
        $this->defaults = $defaults;
    }
}
