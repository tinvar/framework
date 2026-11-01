<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\Authenticatable;

/**
 * A user the accounts kit can sign in: whatever model the application keeps
 * its people in, holding an email address, a name to greet them by and a
 * password hash.
 *
 * The kit never reads the model's table itself. It asks the application's
 * AccountUsers for a user and hands one back to change, so an application
 * that already has a users table -- other columns, another key, a table
 * shared with something else -- implements these three methods on its own
 * model and keeps it.
 */
interface AccountUser extends Authenticatable
{
    /** The address the account signs in with and is mailed at, as AccountUsers keeps it (Emails::normalize()). */
    public function accountEmail(): string;

    /** What the account's mail and pages call its owner. */
    public function accountName(): string;

    /** The password's hash, as Passwords made it. */
    public function accountPasswordHash(): string;
}
