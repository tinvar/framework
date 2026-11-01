<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

/** One #[Notification] class, reflected once. */
final readonly class CompiledNotification
{
    /** The prefix of the unsubscribe list a notification's email turns off: `notify.orders.shipped`. */
    public const string LIST_PREFIX = 'notify.';

    /**
     * @param class-string $class
     * @param list<NotificationChannel> $channels the channels it sends on, by the interfaces it implements
     * @param list<NotificationChannel> $defaults the channels a user who has chosen nothing gets
     */
    public function __construct(
        public string $class,
        public string $type,
        public string $label,
        public string $description,
        public array $channels,
        public array $defaults,
    ) {}

    public function sends(NotificationChannel $channel): bool
    {
        return \in_array($channel, $this->channels, true);
    }

    /**
     * The channels a user gets, given what they chose for this kind: each
     * channel it sends on that they turned on, or -- where they chose
     * nothing -- that is a default. A choice for a channel it does not send
     * on turns nothing on.
     *
     * @param array<string, bool> $chosen channel value => on, as NotificationPreferences keeps it
     * @return list<NotificationChannel>
     */
    public function channelsFor(array $chosen): array
    {
        return array_values(array_filter(
            $this->channels,
            fn (NotificationChannel $channel): bool => $chosen[$channel->value] ?? \in_array($channel, $this->defaults, true),
        ));
    }

    /** The unsubscribe list its email's one-click link names. */
    public function unsubscribeList(): string
    {
        return self::LIST_PREFIX . $this->type;
    }

    public function where(): string
    {
        return "{$this->class} (notification '{$this->type}')";
    }
}
