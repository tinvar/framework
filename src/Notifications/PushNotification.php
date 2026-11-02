<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Auth\Authenticatable;
use Tinvar\Data\Data;
use Tinvar\Jobs\Queueable;

/**
 * A notification that sends a web push (ADR 0152).
 *
 * push() is asked on the worker, with the user it is for, and its Data is
 * what the service worker's push event reads: encoded as a render's data
 * is, and held to the size one push carries. It goes to every browser the
 * user subscribed in the tenant at the auth epoch they are on now, one
 * SendPush job each (PushNotifications); a subscription made before a
 * sign-out or a role change is removed instead. Return null to send nothing.
 */
interface PushNotification extends Queueable
{
    public function push(Authenticatable $user): ?Data;
}
