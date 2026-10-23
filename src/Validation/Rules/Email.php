<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/** A syntactically valid email address. */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Email implements Rule
{
    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false
            ? "{$field} must be a valid email address."
            : null;
    }
}
