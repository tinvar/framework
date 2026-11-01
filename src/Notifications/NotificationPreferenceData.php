<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Data\Data;

/** One kind of notification on a preferences page: what it is called, the channels it sends on, and those the user gets. */
final readonly class NotificationPreferenceData extends Data
{
    /**
     * @param list<string> $channels the channels it sends on: 'mail', 'push', 'inbox'
     * @param list<string> $enabled those the user gets now, by their choice or the defaults
     */
    public function __construct(
        public string $type,
        public string $label,
        public string $description,
        public array $channels,
        public array $enabled,
    ) {}
}
