<?php

declare(strict_types=1);

namespace Tinvar\Validation;

/**
 * One constraint on one field.
 *
 * Rules are attributes, so the declaration lives on the property it describes
 * and nothing has to be repeated in a method body.
 */
interface Rule
{
    /**
     * Returns an error message, or null when the value passes.
     *
     * The value has already been coerced to the property's declared type, so a
     * rule never has to re-check the type.
     */
    public function check(mixed $value, string $field, ValidationContext $context): ?string;
}
