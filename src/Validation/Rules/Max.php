<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/** Upper bound, mirroring Min. */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Max implements Rule
{
    public function __construct(public int|float $max) {}

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return $value > $this->max ? "{$field} must be at most {$this->max}." : null;
        }
        if (\is_string($value)) {
            return mb_strlen($value) > $this->max
                ? "{$field} must be at most {$this->max} characters."
                : null;
        }
        if (\is_array($value)) {
            return \count($value) > $this->max ? "{$field} must have at most {$this->max} items." : null;
        }

        return null;
    }
}
