<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

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

    public function appliesTo(): array
    {
        return ['string', 'int'];
    }

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        // Standing down, not passing judgement: a check on a model field the
        // call left out was set aside, and what the database holds is not
        // this caller's to learn until it has been asked.
        if (!$context->lookups) {
            return null;
        }

        $column = Column::resolve($this->model, $this->column, self::class, $field);

        return $context->orm()->whereIn($this->model, $column, [$value]) === []
            ? "{$field} does not match an existing record."
            : null;
    }
}
