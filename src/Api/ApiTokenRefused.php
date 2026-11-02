<?php

declare(strict_types=1);

namespace Tinvar\Api;

/**
 * A token its user asked for that will not be issued, and which of their
 * choices is why: the message is written for them, so a settings page
 * shows it beside the field it names.
 */
final class ApiTokenRefused extends \InvalidArgumentException
{
    public function __construct(public readonly ApiTokenChoice $choice, string $message)
    {
        parent::__construct($message);
    }
}
