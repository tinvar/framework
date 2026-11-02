<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Mail\Unsubscriber;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;

/**
 * The one-click unsubscribe of a notification's email, through the mail
 * module's own link and endpoint (ADR 0048, 0155): a list named
 * `notify.{type}` turns that kind's mail off in the reader's preferences,
 * as unticking it in the inbox's preferences would. Every other list is
 * the application's, and handed to its own Unsubscriber.
 *
 * Run by UnsubscribeEndpoint once the link's token has verified, inside
 * the tenant the link was made in, so the preference written is that
 * tenant's. Idempotent, as the endpoint needs. A type no notification has
 * any more turns nothing off.
 *
 * The `notify.` prefix is reserved. An application list named in it would
 * be taken for a notification's and never reach the application, so its
 * readers' one-click unsubscribe would turn nothing off. The application
 * names its lists beside its unsubscriber, and one in the prefix is
 * refused when this is built, at boot (ADR 0155).
 */
final readonly class NotificationUnsubscriber implements Unsubscriber
{
    /**
     * @param Unsubscriber|null $application the application's own, for every list not a notification's
     * @param list<string|\BackedEnum> $lists the lists $application unsubscribes from, by name or as the
     *        enum cases whose values name them: required with it, and none in the `notify.` prefix
     * @throws \InvalidArgumentException for an application with no lists named, or a list in the prefix
     */
    public function __construct(
        private NotificationRegistry $registry,
        private NotificationPreferences $preferences,
        private Orm $orm,
        private ?Unsubscriber $application = null,
        array $lists = [],
    ) {
        if ($application !== null && $lists === []) {
            throw new \InvalidArgumentException(
                'Give NotificationUnsubscriber the lists ' . $application::class . ' unsubscribes from -- name the lists, '
                . 'as strings or the enum cases naming them -- so none is in the reserved ' . CompiledNotification::LIST_PREFIX . ' prefix.',
            );
        }
        foreach ($lists as $list) {
            $name = $list instanceof \BackedEnum ? (string) $list->value : $list;
            if (str_starts_with(strtolower($name), CompiledNotification::LIST_PREFIX)) {
                throw new \InvalidArgumentException(
                    "The application's unsubscribe list '{$name}' is in the " . CompiledNotification::LIST_PREFIX . ' prefix, which '
                    . 'notifications keep for their own: its one-click link would turn a notification\'s mail off, never the list. Rename it.',
                );
            }
        }
    }

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
