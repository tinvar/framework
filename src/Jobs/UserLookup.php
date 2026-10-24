<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Auth\Authenticatable;

/**
 * The application's users, as a job running as one needs them: found again
 * by id, and asked for the auth epoch they are on now.
 *
 * A job that runs as its dispatcher (#[Queue(asUser: true)]) carries the
 * user's id and auth epoch, and runs only if the user is still there on the
 * same epoch. The epoch is the application's, as for client-held data: it
 * moves on a role change and on impersonation starting or stopping, and a
 * job authorised under the old one must not run under the new.
 */
interface UserLookup
{
    public function find(string $id): ?Authenticatable;

    /** The user's auth epoch now: what AuthContext::login() is handed for them. */
    public function epoch(Authenticatable $user): string;
}
