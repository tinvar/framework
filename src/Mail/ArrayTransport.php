<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** Keeps every message in memory: the mail fake, for tests. Each is given an id, `array-1` and on, as a provider would. */
final class ArrayTransport implements Delivers
{
    /** @var list<Message> */
    public array $sent = [];

    public function send(Message $message): void
    {
        $this->deliver($message);
    }

    public function deliver(Message $message): SentMessage
    {
        $this->sent[] = $message;

        return new SentMessage($message, 'array-' . \count($this->sent), 'array');
    }
}
