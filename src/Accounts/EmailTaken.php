<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/**
 * An address another account already has, met by AccountUsers::create() or
 * changeEmail(): the unique index said no. The kit never shows this to
 * whoever asked -- that would say which addresses have accounts -- and
 * answers as it would have anyway.
 */
final class EmailTaken extends \RuntimeException
{
    public function __construct(string $message = 'That email address already belongs to an account.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
