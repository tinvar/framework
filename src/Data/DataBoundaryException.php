<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * Thrown when something that is not a Data object tries to cross to the renderer.
 *
 * This is a security control, not a convenience check: it runs in every
 * environment, including production (tinvar-spec.md, "Renderer hardening").
 */
final class DataBoundaryException extends \RuntimeException
{
    public static function notData(object $value, string $path): self
    {
        $class = $value::class;
        $hint = match (true) {
            $value instanceof \DateTimeInterface => " Format it to a string inside the Data class.",
            $value instanceof \BackedEnum => " Use its ->value inside the Data class.",
            $value instanceof \UnitEnum => " Map it to a scalar inside the Data class.",
            default => " Only Data objects, scalars, lists and maps may cross to the renderer;"
                . " map {$class} to a Data object first.",
        };

        return new self("Refused to encode {$class} at {$path}.{$hint}");
    }

    public static function badKey(string $path, string $type): self
    {
        return new self("Map key at {$path} must be an int or string, got {$type}.");
    }

    public static function cycle(string $path, string $class): self
    {
        return new self("Refused to encode a cycle at {$path}: {$class} refers back to itself.");
    }

    public static function tooDeep(string $path, int $max): self
    {
        return new self("Refused to encode {$path}: nesting exceeds the {$max}-level limit.");
    }

    public static function badResource(string $path, string $type): self
    {
        return new self("Refused to encode {$type} at {$path}; it has no representation on the wire.");
    }
}
