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
 *
 * Its readers are To, Cc and Bcc (ADR 0116). Bcc is never a header: its
 * readers are on the SMTP envelope alone, so nobody who gets the message
 * learns who else did that way. Its attachments are the files it carries
 * and the images its HTML shows by `cid:`, held to a total a mail server
 * takes once each is base64'd.
 */
final readonly class Message
{
    /** Readers one message may have, To, Cc and Bcc together: past it, it is a list, and a list is sent one by one. */
    public const int MAX_RECIPIENTS = 100;

    /** Every attachment together; base64 takes it to about 20 MB, under the 25 MB most servers take. */
    public const int MAX_ATTACHMENT_BYTES = 15_728_640;

    /** @var list<Address> */
    public array $to;

    /** @var list<Address> */
    public array $cc;

    /** @var list<Address> */
    public array $bcc;

    /** @var list<Attachment> */
    public array $attachments;

    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param list<Attachment> $attachments
     */
    public function __construct(
        public Address $from,
        array $to,
        public string $subject,
        public string $html,
        public string $text,
        public ?Address $replyTo = null,
        public ?Unsubscribe $unsubscribe = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
    ) {
        if ($to === []) {
            throw new \InvalidArgumentException('An email needs someone to go to.');
        }
        if (\count($to) + \count($cc) + \count($bcc) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('An email goes to at most ' . self::MAX_RECIPIENTS . ' readers; send a list one by one.');
        }
        $bytes = 0;
        $ids = [];
        foreach ($attachments as $attachment) {
            $bytes += \strlen($attachment->content);
            if ($attachment->contentId !== null) {
                if (isset($ids[$attachment->contentId])) {
                    throw new \InvalidArgumentException("Two inline images have the content id '{$attachment->contentId}'.");
                }
                $ids[$attachment->contentId] = true;
            }
        }
        if ($bytes > self::MAX_ATTACHMENT_BYTES) {
            throw new \InvalidArgumentException('An email\'s attachments come to at most ' . self::MAX_ATTACHMENT_BYTES . ' bytes together.');
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
        $this->cc = $cc;
        $this->bcc = $bcc;
        $this->attachments = $attachments;
    }

    /**
     * Everyone the SMTP envelope names, To, Cc and Bcc, each address once:
     * a reader named twice is sent the message once.
     *
     * @return list<string>
     */
    public function envelope(): array
    {
        $seen = [];
        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $reader) {
            $seen[strtolower($reader->email)] ??= $reader->email;
        }

        return array_values($seen);
    }
}
