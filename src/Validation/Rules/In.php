<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * A closed set of allowed values.
 *
 * The spec backs sort, filter and column fields with these, generated from
 * Data fields, so a user cannot name a column the page never exposed.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class In implements Rule
{
    /** @var list<string|int> */
    public array $allowed;

    public function __construct(string|int ...$allowed)
    {
        $this->allowed = array_values($allowed);
    }

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        // Strict comparison: '1' must not satisfy an allowed value of 1.
        return \in_array($value, $this->allowed, true)
            ? null
            : "{$field} must be one of: " . implode(', ', array_map(strval(...), $this->allowed)) . '.';
    }
}
