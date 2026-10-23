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

    /**
     * The field kinds this rule can decide about: any of 'string', 'int',
     * 'float', 'bool', 'array', 'enum', 'model'. An empty list means any.
     *
     * Checked when the form request is planned, so a rule attached to a type it
     * cannot judge is a loud authoring error rather than a rule that silently
     * passes everything -- which is the failure mode that matters, because a
     * declared-but-inert constraint reads as enforced.
     *
     * @return list<string>
     */
    public function appliesTo(): array;
}
