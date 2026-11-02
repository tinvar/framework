<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Mail\Unsubscriber;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;

/**
 * The one-click unsubscribe of a notification's email, through the mail
 * module's own link and endpoint (ADR 0048, 0152): a list named
 * `notify.{type}` turns that kind's mail off in the reader's preferences,
 * as unticking it in the inbox's preferences would. Every other list is
 * the application's, and handed to its own Unsubscriber.
 *
 * Run by UnsubscribeEndpoint once the link's token has verified, inside
 * the tenant the link was made in, so the preference written is that
 * tenant's. Idempotent, as the endpoint needs. A type no notification has
 * any more turns nothing off.
 */
final readonly class NotificationUnsubscriber implements Unsubscriber
{
    public function __construct(
        private NotificationRegistry $registry,
        private NotificationPreferences $preferences,
        private Orm $orm,
        private ?Unsubscriber $application = null,
    ) {}

    public function unsubscribe(string $list, string $recipient): void
    {
        if (!str_starts_with($list, CompiledNotification::LIST_PREFIX)) {
            $this->application?->unsubscribe($list, $recipient);

            return;
        }

        $notification = $this->registry->forType(substr($list, \strlen(CompiledNotification::LIST_PREFIX)));
        if ($notification === null || !$notification->sends(NotificationChannel::Mail)) {
            return;
        }

        $this->preferences->choose(TenantClaim::current($this->orm), $recipient, $notification->type, NotificationChannel::Mail, false);
    }
}
