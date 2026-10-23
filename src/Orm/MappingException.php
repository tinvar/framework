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
            . 'name the target explicitly.'
        );
    }

    public static function unsupportedColumnType(string $class, string $property, string $type): self
    {
        return new self("{$class}::\${$property} has unsupported column type {$type}.");
    }
}
