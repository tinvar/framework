<?php

declare(strict_types=1);

namespace Tinvar\Data;

/** A Data object could not be built from its source. */
final class DataMappingException extends \RuntimeException
{
    public static function missing(string $class, string $field, string $source, string $from): self
    {
        return new self(
            "{$class}::\${$field} has nothing to map from: {$from} has no '{$source}'. "
            . "Name it with #[From('...')], give the field a default, or make it nullable.",
        );
    }

    public static function wrongSource(string $class, string $expected, string $given): self
    {
        return new self("{$class} maps from {$expected} (#[MapsFrom]), not {$given}.");
    }

    public static function notIterable(string $class, string $field, string $given): self
    {
        return new self("{$class}::\${$field} is a list, but its source is {$given}.");
    }

    public static function cycle(string $class, string $source): self
    {
        return new self(
            "Mapping {$class} reached a {$source} it is already mapping: the source graph is cyclic. "
            . 'Map the back-reference as an id, or leave it out of the Data class.',
        );
    }

    public static function tooDeep(string $class, int $max): self
    {
        return new self("Mapping {$class} nests deeper than {$max} levels, more than the encoder will write.");
    }
}
