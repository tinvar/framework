<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\UserLookup;

/**
 * The users an API token's holder is found again among, where the epoch a
 * token is held to is not the user's auth epoch (ADR 0159): a credential
 * epoch, which moves on fewer things, so a token outlives each sign-in.
 *
 * epoch() is what a token is bound to and checked against; authEpoch() is
 * what its request runs on -- the epoch every job it dispatches, link it
 * mints and answer it caches is bound to, and checked against elsewhere.
 * Run on the credential epoch, a token's request bound them to a number
 * from another counter: a job it queued was refused as on another epoch,
 * and a cached answer keyed by it outlived the role change that moves the
 * auth epoch alone -- or matched a session's entry whose auth epoch
 * happened to be the same number.
 *
 * A lookup that is not one of these is taken to report the auth epoch, and
 * a token's request runs on it.
 */
interface TokenHolderLookup extends UserLookup
{
    /** The user's auth epoch now, as AuthContext takes it for a session's request. */
    public function authEpoch(Authenticatable $user): string;
}
