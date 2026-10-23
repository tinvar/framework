<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * A regular expression the whole value must match.
 *
 * The pattern is anchored here rather than trusted to be anchored by the
 * author: an unanchored pattern that merely matches somewhere inside the value
 * is the usual way a validation regex ends up accepting far more than intended.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Pattern implements Rule
{
    public function __construct(
        public string $pattern,
        public ?string $message = null,
    ) {}

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $anchored = '/\A(?:' . str_replace('/', '\/', $this->pattern) . ')\z/u';
        $matched = @preg_match($anchored, $value);

        if ($matched === false) {
            throw new \LogicException("Rule Pattern on {$field} has an invalid expression.");
        }

        return $matched === 1 ? null : ($this->message ?? "{$field} is not in the expected format.");
    }
}
