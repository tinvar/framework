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
    public function __construct(public string $email, public string $name = '')
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n<>]/', $email) === 1) {
            throw new \InvalidArgumentException("'{$email}' is not an email address.");
        }
        if (preg_match('/[\r\n]/', $name) === 1) {
            throw new \InvalidArgumentException('A display name cannot hold a line break.');
        }
    }

    /** As a header writes it, the name encoded when it is not plain ASCII. */
    public function toHeader(): string
    {
        if ($this->name === '') {
            return "<{$this->email}>";
        }

        $name = preg_match('/^[A-Za-z0-9 .\'_-]*\z/', $this->name) === 1
            ? '"' . $this->name . '"'
            : '=?UTF-8?B?' . base64_encode($this->name) . '?=';

        return "{$name} <{$this->email}>";
    }
}
