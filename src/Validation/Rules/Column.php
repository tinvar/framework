<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;

/**
 * Resolves and vets a column name for a database-backed rule.
 *
 * The name comes from an attribute, not from a request, but it still reaches
 * SQL -- so it is checked against the model's compiled metadata rather than
 * trusted. The query builder is meant to reject identifiers that do not come
 * from compiled metadata; this is that rule applied at the validation layer.
 */
final class Column
{
    /** @var array<string, string> */
    private static array $resolved = [];

    /** @param class-string<Model> $model */
    public static function resolve(string $model, ?string $column, string $rule, string $field): string
    {
        $key = $model . '|' . ($column ?? '');
        if (isset(self::$resolved[$key])) {
            return self::$resolved[$key];
        }

        return self::$resolved[$key] = self::lookUp($model, $column, $rule, $field);
    }

    /** @param class-string<Model> $model */
    private static function lookUp(string $model, ?string $column, string $rule, string $field): string
    {
        $meta = Metadata::for($model);

        if ($column === null) {
            return $meta->idColumn;
        }

        $known = $meta->selectColumns();
        if (\in_array($column, $known, true)) {
            return $column;
        }

        // Allow the property name as well as the column name, since that is
        // what an author is likely to write.
        if (isset($meta->columns[$column])) {
            return $meta->columns[$column];
        }

        throw new \LogicException(
            "{$rule} on {$field} names column '{$column}', which {$model} does not declare. "
            . 'Known columns: ' . implode(', ', $known) . '.'
        );
    }
}
