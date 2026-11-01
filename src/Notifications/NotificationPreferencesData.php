<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Data\Data;

/** Every kind of notification the application sends, with the channels the user gets for each. */
final readonly class NotificationPreferencesData extends Data
{
    /** @param list<NotificationPreferenceData> $types */
    public function __construct(public array $types) {}
}
