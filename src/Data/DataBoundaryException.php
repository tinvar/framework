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

    public static function unplaced(ProvidesData $value, string $path): self
    {
        return new self(
            'Refused to encode ' . $value::class . " at {$path}: a ProvidesData becomes its Data on a page -- a "
            . "shared value or a loader's field -- and this value is placed on none. Return the Data object itself.",
        );
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

    public static function reservedKey(string $path, string $key): self
    {
        return new self(
            "Refused to encode the map key '{$key}' at {$path}: the renderer cannot hold it as data. "
            . 'Rename the key, or carry the map as a list of pairs.',
        );
    }
}
