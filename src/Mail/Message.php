<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * One email, rendered and ready to send: who it is from and to, its
 * subject, and both parts -- HTML and plain text.
 *
 * Both parts, always. Meridian's MER-36.2 requires "a plain-text version
 * where every link also appears as text", and a message with no text part
 * is one many filters score as spam; so a Message cannot be made without
 * one.
 */
final readonly class Message
{
    /** @var list<Address> */
    public array $to;

    /** @param list<Address> $to */
    public function __construct(
        public Address $from,
        array $to,
        public string $subject,
        public string $html,
        public string $text,
        public ?Address $replyTo = null,
        public ?Unsubscribe $unsubscribe = null,
    ) {
        if ($to === []) {
            throw new \InvalidArgumentException('An email needs someone to go to.');
        }
        if (trim($subject) === '' || Header::breaks($subject)) {
            throw new \InvalidArgumentException('An email needs a subject on one line.');
        }
        if (mb_strlen($subject, 'UTF-8') > Header::MAX_SUBJECT) {
            throw new \InvalidArgumentException('An email subject is at most ' . Header::MAX_SUBJECT . ' characters.');
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException('An email needs a plain-text part.');
        }

        $this->to = $to;
    }
}
