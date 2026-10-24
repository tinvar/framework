<?php

declare(strict_types=1);

namespace Tinvar\Testing\Fakes;

use Tinvar\Mail\Message;

/** One email the MailFake kept: the template and props it was drawn from, and the message. */
final readonly class SentMail
{
    /** @param array<string, mixed> $props encoded, as the renderer was handed them */
    public function __construct(
        public string $template,
        public array $props,
        public Message $message,
    ) {}

    /** Whether $address is among those it went to, ignoring case. */
    public function isTo(string $address): bool
    {
        foreach ($this->message->to as $to) {
            if (strcasecmp($to->email, $address) === 0) {
                return true;
            }
        }

        return false;
    }
}
