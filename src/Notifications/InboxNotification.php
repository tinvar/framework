<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Auth\Authenticatable;
use Tinvar\Jobs\Queueable;

/**
 * A notification that puts an entry in the user's in-app inbox (ADR 0155).
 *
 * inbox() is asked on the worker, with the user it is for, and what it
 * answers is written to their feed in the tenant in force -- the one the
 * notification was sent in -- and announced to every page they have open
 * on a personal channel, so the bell counts it without a reload. The
 * message is text, never markup, and links only to a path on this site.
 * Return null to write nothing.
 */
interface InboxNotification extends Queueable
{
    public function inbox(Authenticatable $user): ?InboxMessage;
}
