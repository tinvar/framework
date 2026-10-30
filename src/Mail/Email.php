<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Data\Data;

/**
 * What a Mailable says to send: to whom, with what subject, and which
 * template the renderer draws it with, from what props.
 *
 * The props are a Data object, as a page's loader data is, so they cross
 * to the renderer through the same typed boundary -- Data objects, scalars,
 * lists and maps, never a model -- and nothing the template is not given
 * can leak into the message.
 *
 * Meridian's MER-36.4: "say what happened in the subject and first line".
 * The subject is the application's to write; a Message refuses an empty one.
 *
 * Mail a reader can stop getting -- a newsletter, a digest -- says how in
 * $unsubscribe, which becomes its List-Unsubscribe headers (ADR 0048).
 *
 * $origin is the canonical origin the message's links are built on -- what
 * CanonicalOrigins answers for its reader -- and $images any further https
 * origins its images may load from. Together they are the message's
 * LinkPolicy (ADR 0086): with no origin, its links are https or mailto:
 * alone and it loads no image at all. $tracking names the origins a
 * tracking image may load from, query and all; nothing else may carry one.
 *
 * $cc and $bcc are written as a Message writes them (ADR 0116): Cc in the
 * headers, Bcc never -- its readers are on the envelope alone. And
 * $attachments are the files it carries, built here on the worker; an
 * inline one's content id is the one `cid:` its HTML may name.
 */
final readonly class Email
{
    /** @var list<Address> */
    public array $to;

    /** @var list<Address> */
    public array $cc;

    /** @var list<Address> */
    public array $bcc;

    /** @var list<Attachment> */
    public array $attachments;

    public LinkPolicy $links;

    /**
     * @param Address|list<Address> $to
     * @param list<string> $images
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param list<Attachment> $attachments
     * @param list<string> $tracking
     */
    public function __construct(
        Address|array $to,
        public string $subject,
        public string $template,
        public Data $props,
        public ?Address $replyTo = null,
        public ?Unsubscribe $unsubscribe = null,
        ?string $origin = null,
        array $images = [],
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
        array $tracking = [],
    ) {
        $this->to = $to instanceof Address ? [$to] : $to;
        $this->cc = $cc;
        $this->bcc = $bcc;
        $this->attachments = $attachments;
        $inline = [];
        $files = [];
        foreach ($attachments as $attachment) {
            if ($attachment->contentId === null) {
                $files[] = $attachment->describe();
            } elseif (\in_array($attachment->contentId, $inline, true)) {
                throw new \InvalidArgumentException("Two inline images have the content id '{$attachment->contentId}'.");
            } else {
                $inline[] = $attachment->contentId;
            }
        }
        $this->links = new LinkPolicy($origin, $images, $tracking, $inline, $files);
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}\z/', $template) !== 1) {
            throw new \InvalidArgumentException("'{$template}' is not a template name: lowercase words joined by '-'.");
        }
    }
}
