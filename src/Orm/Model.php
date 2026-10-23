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
