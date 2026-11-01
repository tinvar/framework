<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Someone mail is from or to: an address, and a display name if any.
 *
 * Checked here, because both end up in headers: a CR or LF in either is a
 * header of the attacker's choosing -- a Bcc to themselves -- written into
 * a message the application thought it addressed.
 */
final readonly class Address
{
    /** The longest display name written quoted on the header's line; a longer one is folded as encoded words. */
    private const int PLAIN_NAME = 64;

    public function __construct(public string $email, public string $name = '')
    {
        // Nor quotes, commas or semicolons, which a quoted local part may
        // hold and FILTER_VALIDATE_EMAIL accepts: an address list is split on
        // them by more parsers than honour the quotes.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\x00-\x20\x7f<>",;]/', $email) === 1) {
            throw new \InvalidArgumentException("'{$email}' is not an email address.");
        }
        if (Header::breaks($name)) {
            throw new \InvalidArgumentException('A display name cannot hold a line break or a control character.');
        }
    }

    /** As a header writes it, the name encoded when it is not plain ASCII. */
    public function toHeader(): string
    {
        if ($this->name === '') {
            return "<{$this->email}>";
        }

        // Quoted when it is a short run of letters, digits and the few
        // marks no parser reads as anything. Anything else -- a quote, a
        // `<`, a comma, a `;`, which would end the name early or start an
        // address of its own in the list a client parses, or a name too long
        // for one line -- encoded as words of at most 75 bytes, folded,
        // plain ASCII or not.
        $name = \strlen($this->name) <= self::PLAIN_NAME && preg_match('/^[A-Za-z0-9 .\'_-]*\z/', $this->name) === 1
            ? '"' . $this->name . '"'
            : Header::encode($this->name, words: true);

        return "{$name} <{$this->email}>";
    }
}
