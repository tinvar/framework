<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A transport that can be held to a time: within($seconds) is the same
 * transport, giving up on a send after $seconds at most -- or after its own
 * timeout, when that is shorter.
 *
 * A FailoverTransport holds each transport it tries to what is left of its
 * one deadline (ADR 0147), so a failover across slow providers ends inside
 * SendMail's timeout. The API providers and SMTP are bounded; a transport
 * that is not is tried only while time is left, and its own timeout is its
 * own.
 */
interface Bounded extends Transport
{
    /** A copy that gives up on a send after $seconds at most. */
    public function within(float $seconds): static;
}
