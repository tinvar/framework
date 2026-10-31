<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** How two providers or more in TINVAR_MAIL_PROVIDER share the mail, as TINVAR_MAIL_STRATEGY says. */
enum MailStrategy: string
{
    /** The first, and the next only when it fails (FailoverTransport). */
    case Failover = 'failover';

    /** Each message the next, failing over from there (RoundRobinTransport). */
    case RoundRobin = 'roundrobin';
}
