<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * The value must name an existing row.
 *
 * The lookup goes through the ORM, so it inherits tenant scoping: the spec
 * requires Exists to be tenant-scoped, and a rule issuing its own SQL would
 * quietly escape that. The column is checked against compiled metadata, so a
 * typo fails loudly and nothing unvetted reaches a query.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Exists implements Rule
{
    /** @param class-string<Model> $model */
    public function __construct(
        public string $model,
        public ?string $column = null,
    ) {}

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        if ($value === null || $value === '') {
            return null;   // emptiness is Required's business, not this rule's
        }

        $column = Column::resolve($this->model, $this->column, self::class, $field);

        return $context->orm()->whereIn($this->model, $column, [$value]) === []
            ? "{$field} does not match an existing record."
            : null;
    }
}
