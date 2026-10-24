<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** Where a rendered message goes: an SMTP server, a log, memory. */
interface Transport
{
    public function send(Message $message): void;
}
