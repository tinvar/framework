<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Base model. Columns are declared on the class, relations and behaviour in
 * traits (tinvar-spec.md, "Models").
 *
 * Phase 0 implements hydration, identity and lazy relations. Writes, change
 * tracking, scopes and the query builder are later phases; the brief asks for
 * the ORM to stay as small as the prototype needs.
 */
abstract class Model
{
    private ?ResultSet $resultSet = null;
    private ?Orm $orm = null;

    /** @var array<string, mixed> foreign-key values read from the row, keyed by relation property */
    private array $foreignKeys = [];

    /**
     * Column values as they were when the row was read.
     *
     * "Original column values are snapshotted on hydrate; save() writes only
     * the diff." Null means this model has never been in the database.
     *
     * @var array<string, mixed>|null
     */
    private ?array $original = null;

    public static function meta(): ModelMeta
    {
        return Metadata::for(static::class);
    }

    public function primaryKey(): string
    {
        return static::meta()->keyOf($this);
    }

    /**
     * The models hydrated alongside this one. Relation loads widen to cover
     * them, which is what keeps a result set at 1+1 queries.
     */
    public function resultSet(): ?ResultSet
    {
        return $this->resultSet;
    }

    public function orm(): ?Orm
    {
        return $this->orm;
    }

    public function foreignKey(string $relationProperty): mixed
    {
        return $this->foreignKeys[$relationProperty] ?? null;
    }

    /** True once the row exists in the database. */
    public function isPersisted(): bool
    {
        return $this->original !== null;
    }

    /** @return array<string, mixed> */
    public function originalValues(): array
    {
        return $this->original ?? [];
    }

    /**
     * Columns whose value differs from the snapshot.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        if ($this->original === null) {
            return static::meta()->columnValues($this);
        }

        $changes = [];
        foreach (static::meta()->columnValues($this) as $column => $value) {
            if (!\array_key_exists($column, $this->original) || $this->original[$column] !== $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    public function isDirty(): bool
    {
        return $this->changes() !== [];
    }

    /** @internal called by Orm after a read or a write @param array<string, mixed> $values */
    public function markClean(array $values): void
    {
        $this->original = $values;
    }

    /** @internal called by Orm during hydration */
    public function attach(Orm $orm, ResultSet $resultSet): void
    {
        $this->orm = $orm;
        $this->resultSet = $resultSet;
    }

    /** @internal called by Orm during hydration */
    public function rememberForeignKey(string $relationProperty, mixed $value): void
    {
        $this->foreignKeys[$relationProperty] = $value;
    }
}
