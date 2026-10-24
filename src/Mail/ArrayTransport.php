<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** Keeps every message in memory: the mail fake, for tests. */
final class ArrayTransport implements Transport
{
    /** @var list<Message> */
    public array $sent = [];

    public function send(Message $message): void
    {
        $this->sent[] = $message;
    }
}
