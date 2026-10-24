<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * What the application does when a reader unsubscribes with one click: it
 * stops sending them $list. Called by UnsubscribeEndpoint once a link's
 * token has verified, inside the tenant the link was made in, as nobody --
 * the request comes from a mail provider's server, with no session.
 *
 * Idempotent, since a provider may POST the same link more than once, and
 * quick: the provider is waiting on the answer.
 */
interface Unsubscriber
{
    /**
     * @param string $list      the kind of mail, as UnsubscribeLinks was told it
     * @param string $recipient whom it is for, as the application named them
     */
    public function unsubscribe(string $list, string $recipient): void;
}
