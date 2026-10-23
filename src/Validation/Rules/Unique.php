<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * No other row may already hold this value.
 *
 * `ignore` is the id of the row being edited, so an update that leaves a field
 * unchanged is not rejected by its own row.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Unique implements Rule
{
    /** @param class-string<Model> $model */
    public function __construct(
        public string $model,
        public ?string $column = null,
        public ?string $ignore = null,
    ) {}

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $column = Column::resolve($this->model, $this->column, self::class, $field);
        $matches = $context->orm()->whereIn($this->model, $column, [$value]);

        // The row being edited does not collide with itself. `ignore` names the
        // input field holding its id rather than carrying an id in an
        // attribute, because an attribute is compiled once and an id is not.
        $ignoredId = $this->ignore === null ? null : ($context->input[$this->ignore] ?? null);

        foreach ($matches as $match) {
            if ($ignoredId === null || $match->primaryKey() !== (string) $ignoredId) {
                return "{$field} is already taken.";
            }
        }

        return null;
    }
}
