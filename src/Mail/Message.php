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
 *
 * Its tags and metadata are for the provider that sends it, never for the
 * reader: labels and key=value pairs Resend, SES, Postmark, Mailgun and
 * SendGrid each keep beside the message and hand back with its bounces,
 * complaints and deliveries. Held to what all five take -- letters, digits,
 * `_` and `-` -- so a tag that one provider accepts is not refused by the
 * next a failover sends it through. SMTP sends neither.
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

    /** The most tags, and the most metadata entries, one message carries: SendGrid's and SES's limits are higher. */
    public const int MAX_TAGS = 10;

    /** What a tag, a metadata key or a metadata value may be, for every provider that keeps them. */
    public const string TAG = '/^[A-Za-z0-9_-]{1,128}\z/';

    /** @var list<string> */
    public array $tags;

    /** @var array<string, string> */
    public array $metadata;

    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param list<Attachment> $attachments
     * @param list<string> $tags labels the provider keeps with it
     * @param array<string, string> $metadata key => value pairs the provider keeps with it
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
        array $tags = [],
        array $metadata = [],
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

        self::assertTags($tags, $metadata);

        $this->to = $to;
        $this->cc = $cc;
        $this->bcc = $bcc;
        $this->attachments = $attachments;
        $this->tags = $tags;
        $this->metadata = $metadata;
    }

    /**
     * Refuses tags or metadata a provider would: too many, or anything but
     * letters, digits, `_` and `-`.
     *
     * @param array<array-key, mixed> $tags
     * @param array<array-key, mixed> $metadata
     */
    public static function assertTags(array $tags, array $metadata): void
    {
        if (\count($tags) > self::MAX_TAGS || \count($metadata) > self::MAX_TAGS) {
            throw new \InvalidArgumentException('An email carries at most ' . self::MAX_TAGS . ' tags and ' . self::MAX_TAGS . ' metadata entries.');
        }
        if (!array_is_list($tags)) {
            throw new \InvalidArgumentException('An email\'s tags are a list.');
        }
        foreach ($tags as $tag) {
            if (!\is_string($tag) || preg_match(self::TAG, $tag) !== 1) {
                throw new \InvalidArgumentException('A tag is 1 to 128 letters, digits, _ or -.');
            }
        }
        foreach ($metadata as $key => $value) {
            if (!\is_string($key) || preg_match(self::TAG, $key) !== 1 || !\is_string($value) || preg_match(self::TAG, $value) !== 1) {
                throw new \InvalidArgumentException('Metadata is key => value, each 1 to 128 letters, digits, _ or -.');
            }
        }
    }

    /**
     * The same message to fewer readers: those left once the suppression
     * list has had its say. Null when no To reader is left -- a message
     * whose readers were all copies is not sent to the copies alone.
     *
     * @param \Closure(Address): bool $keep
     */
    public function keeping(\Closure $keep): ?self
    {
        $to = array_values(array_filter($this->to, $keep));
        if ($to === []) {
            return null;
        }

        return new self(
            $this->from,
            $to,
            $this->subject,
            $this->html,
            $this->text,
            $this->replyTo,
            $this->unsubscribe,
            array_values(array_filter($this->cc, $keep)),
            array_values(array_filter($this->bcc, $keep)),
            $this->attachments,
            $this->tags,
            $this->metadata,
        );
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
