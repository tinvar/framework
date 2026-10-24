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
 */
final readonly class Email
{
    /** @var list<Address> */
    public array $to;

    /** @param Address|list<Address> $to */
    public function __construct(
        Address|array $to,
        public string $subject,
        public string $template,
        public Data $props,
        public ?Address $replyTo = null,
    ) {
        $this->to = $to instanceof Address ? [$to] : $to;
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}\z/', $template) !== 1) {
            throw new \InvalidArgumentException("'{$template}' is not a template name: lowercase words joined by '-'.");
        }
    }
}
