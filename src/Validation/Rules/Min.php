<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * Lower bound. On a number it constrains the value; on a string or array it
 * constrains the length, which is what callers mean in both cases.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Min implements Rule
{
    public function __construct(public int|float $min) {}

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return $value < $this->min ? "{$field} must be at least {$this->min}." : null;
        }
        if (\is_string($value)) {
            return mb_strlen($value) < $this->min
                ? "{$field} must be at least {$this->min} characters."
                : null;
        }
        if (\is_array($value)) {
            return \count($value) < $this->min ? "{$field} must have at least {$this->min} items." : null;
        }

        return null;
    }
}
