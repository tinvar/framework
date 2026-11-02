<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Http\HasHttpStatus;

/**
 * A token its user asked for that will not be issued, and which of their
 * choices is why: the message is written for them, so a settings page
 * shows it beside the field it names -- or, for ApiTokenChoice::Caller,
 * above the form.
 *
 * $status is what it is answered with where nothing catches it
 * (ExceptionHandler, through HasHttpStatus), with the code
 * `api_token_refused`: 422 for a choice that will not do, 403 for a caller
 * who may not have a token made at all.
 */
final class ApiTokenRefused extends \InvalidArgumentException implements HasHttpStatus
{
    public function __construct(public readonly ApiTokenChoice $choice, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function httpCode(): string
    {
        return 'api_token_refused';
    }
}
