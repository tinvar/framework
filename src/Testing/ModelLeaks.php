<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Attributes\From;
use Tinvar\Attributes\MapsFrom;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * "assertNoModelLeak() scans the RSC payload for any model column not
 * declared on a Data object, so a leaked password hash fails CI."
 *
 * The encoder already refuses a model at the boundary, so a leak cannot be a
 * model crossing whole. It is a column's value copied into a Data field --
 * `new CustomerData(..., hash: $customer->passwordHash)` -- or into a string
 * a field carries. So the check is by value: every row the request read is
 * snapshotted before the reset clears it, and the value of each column no
 * Data object declares is looked for in what was sent.
 *
 * Every row read is the Orm's record of reads (Orm::recordReads()), which
 * the client turns on around a render, beside the identity map. The map
 * alone held too little: a row read across tenants or a subtree never
 * enters it, and leaving acrossTenants() empties it, so a staff page's
 * reads -- and everything a page read before one acrossTenants() call --
 * were never looked for.
 *
 * Declared means named by a Data field. A model some Data class says it
 * #[MapsFrom] is held to those classes' fields and #[From] sources; any other
 * model to the fields of every Data class of its application -- loaded, and
 * under the model's own top namespace, so a test fixture elsewhere in the
 * process cannot declare a column for it. That is lenient on purpose: a
 * column a Data field shows by name is one a page may well show, and this is
 * a guard against the column nobody meant to show.
 *
 * Only strings of MIN_LENGTH or more are looked for: a status, a count or a
 * flag would match page text by accident, and what this exists to catch --
 * a hash, a token, an internal note -- is long. A value some declared column
 * of a row the request read also holds is not looked for either: the page
 * may show it through that column.
 */
final class ModelLeaks
{
    public const int MIN_LENGTH = 8;

    /**
     * The undeclared column values of every row the request has read so far,
     * less any a declared column also holds.
     *
     * @return list<array{model: class-string, column: string, value: string}>
     */
    public static function snapshot(Orm $orm): array
    {
        $undeclared = [];
        $declared = [];
        $exposedBy = [];

        $read = [];
        foreach ([...$orm->identityMap()->all(), ...$orm->readsRecorded()] as $model) {
            $read[spl_object_id($model)] = $model;
        }

        foreach ($read as $model) {
            // A ghost a relation registered and nothing loaded holds no row;
            // reading its properties would load one.
            if (!$model instanceof Model || !Orm::holdsRow($model)) {
                continue;
            }

            $meta = Metadata::for($model::class);
            $exposed = $exposedBy[$model::class] ??= self::declaredFor($model::class);
            $values = $meta->columnValues($model);

            foreach ($meta->columns as $property => $column) {
                $value = $values[$column] ?? null;
                if (!\is_string($value)) {
                    continue;
                }
                if (isset($exposed[$property]) || $property === $meta->idProperty) {
                    $declared[$value] = true;
                    continue;
                }
                if (\strlen($value) >= self::MIN_LENGTH) {
                    $undeclared[] = ['model' => $model::class, 'column' => $column, 'value' => $value];
                }
            }
        }

        return array_values(array_filter(
            $undeclared,
            static fn (array $leak): bool => !isset($declared[$leak['value']]),
        ));
    }

    /**
     * Which of $snapshot's values appear in $sent, as the payload and the
     * HTML would spell them.
     *
     * @param list<array{model: class-string, column: string, value: string}> $snapshot
     * @return list<array{model: class-string, column: string, value: string}>
     */
    public static function find(array $snapshot, string $sent): array
    {
        $found = [];
        foreach ($snapshot as $leak) {
            foreach (self::spellings($leak['value']) as $spelling) {
                if ($spelling !== '' && str_contains($sent, $spelling)) {
                    $found[] = $leak;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The fields that declare a model's columns: those of the Data classes
     * mapping from it, or of its application's when none says it does.
     *
     * @param class-string $model
     * @return array<string, true>
     */
    private static function declaredFor(string $model): array
    {
        $mapping = [];
        $all = [];
        $root = strstr($model, '\\', true);
        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, Data::class)) {
                continue;
            }
            $ours = $root === false || str_starts_with($class, $root . '\\');

            $fields = self::fieldsOf($class);
            if ($ours) {
                $all += $fields;
            }
            foreach ((new \ReflectionClass($class))->getAttributes(MapsFrom::class) as $attribute) {
                if ($attribute->newInstance()->model === $model) {
                    $mapping += $fields;
                }
            }
        }

        return $mapping === [] ? $all : $mapping;
    }

    /**
     * A Data class's fields, by name and by the model property each #[From]
     * names.
     *
     * @param class-string $class
     * @return array<string, true>
     */
    private static function fieldsOf(string $class): array
    {
        $fields = [];
        foreach (DataShape::fieldsOf($class) as $property) {
            $fields[$property->getName()] = true;
            $from = $property->getAttributes(From::class)[0] ?? null;
            if ($from === null && $property->isPromoted()) {
                $parameter = new \ReflectionParameter([$class, '__construct'], $property->getName());
                $from = $parameter->getAttributes(From::class)[0] ?? null;
            }
            if ($from !== null) {
                $fields[explode('.', $from->newInstance()->property)[0]] = true;
            }
        }

        return $fields;
    }

    /** @return list<string> */
    private static function spellings(string $value): array
    {
        // As JavaScript writes it into a payload row, and as PHP writes JSON
        // -- the page metadata escapes every slash.
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $escaped = json_encode($value);

        return array_values(array_unique([
            $value,
            \is_string($json) ? substr($json, 1, -1) : '',
            \is_string($escaped) ? substr($escaped, 1, -1) : '',
            htmlspecialchars($value, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($value, ENT_QUOTES),
        ]));
    }
}
