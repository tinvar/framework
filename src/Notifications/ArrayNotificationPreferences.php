<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

/** Notification preferences in memory, for tests and a single process. */
final class ArrayNotificationPreferences implements NotificationPreferences
{
    /** @var array<string, array<string, array<string, array<string, bool>>>> tenant => user => type => channel => on */
    public array $chosen = [];

    public function chosen(string $tenant, string $user): array
    {
        return $this->chosen[$tenant][$user] ?? [];
    }

    public function choose(string $tenant, string $user, string $type, NotificationChannel $channel, bool $on): void
    {
        $this->chosen[$tenant][$user][$type][$channel->value] = $on;
    }
}
