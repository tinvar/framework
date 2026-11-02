<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

/**
 * Which channels each user wants for each kind of notification, per
 * tenant (ADR 0154): only what they chose -- a kind or a channel they
 * never touched is absent, and gets its notification's defaults
 * (CompiledNotification::channelsFor()), so a default changed later
 * reaches everyone who left it alone.
 *
 * Written from the inbox's preferences call by the user, and by a
 * notification email's one-click unsubscribe (NotificationUnsubscriber),
 * which turns that kind's mail off. Every read and write names the tenant
 * claim and the user.
 */
interface NotificationPreferences
{
    /**
     * What $user chose in $tenant.
     *
     * @return array<string, array<string, bool>> type => channel value => on
     */
    public function chosen(string $tenant, string $user): array;

    /** Records that $user wants $channel on, or off, for $type in $tenant. */
    public function choose(string $tenant, string $user, string $type, NotificationChannel $channel, bool $on): void;
}
