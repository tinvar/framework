<?php

declare(strict_types=1);

namespace Tinvar\Services;

use Tinvar\Auth\Authenticatable;

/**
 * Another system, signed in to this application as itself: a warehouse, a
 * carrier, a gateway relaying an agent's report. What the application's own
 * authentication signs in -- a bearer token checked by middleware, a client
 * certificate its proxy verified -- for an endpoint that takes service
 * calls (ServiceCalls).
 *
 * Its name is the one a service context token is issued to, and the one an
 * operation's #[ServiceCall] lists: a token issued to the warehouse is good
 * only in a call the warehouse makes, signed in as the warehouse. Policies
 * see it as the user, and decide what it may do as they would for anyone.
 */
interface Service extends Authenticatable
{
    /** Letters, digits, '.', '_' and '-', up to 64. */
    public function serviceName(): string;
}
