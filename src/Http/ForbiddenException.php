<?php

declare(strict_types=1);

namespace Tinvar\Http;

/** 403: the subject exists and the check said no. */
final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden.')
    {
        parent::__construct(403, $message, 'forbidden');
    }

    public static function check(string $ability, string $on): self
    {
        return new self("'{$ability}' denied on {$on}.");
    }
}
