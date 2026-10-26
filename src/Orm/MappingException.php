<?php

declare(strict_types=1);

namespace Tinvar\Orm;

final class MappingException extends \RuntimeException
{
    public static function noTable(string $class): self
    {
        return new self("{$class} has no #[Table] attribute.");
    }

    public static function noId(string $class): self
    {
        return new self("{$class} has no #[Id] property.");
    }

    public static function untypedRelation(string $class, string $property): self
    {
        return new self(
            "{$class}::\${$property} has #[BelongsTo] but no class type to infer its target from; "
            . 'name the target explicitly.',
        );
    }

    /** @param list<string> $known */
    public static function unknownIdentifier(string $class, string $column, array $known): self
    {
        return new self(
            "{$class} has no column '{$column}'. The query builder only accepts identifiers from compiled "
            . 'metadata, so a sort or filter field cannot name a hidden column. Known: ' . implode(', ', $known) . '.',
        );
    }

    public static function malformedIdentifier(string $column): self
    {
        return new self(
            "'{$column}' is not a well-formed column name. Identifiers are never interpolated from input.",
        );
    }

    public static function relationTargetNotModel(string $class, string $property, string $target): self
    {
        return new self("{$class}::\${$property} relates to {$target}, which is not a Model.");
    }

    public static function unsupportedColumnType(string $class, string $property, string $type): self
    {
        return new self("{$class}::\${$property} has unsupported column type {$type}.");
    }

    public static function unusableHook(string $class, string $method): self
    {
        return new self("{$class}: #[BeforeSave] {$method} must be an instance method that takes no arguments.");
    }
}
