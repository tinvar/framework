<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * The only object kind allowed across the PHP-to-renderer boundary.
 *
 * Models never cross. Pages, queries and actions return Data objects, which
 * keeps columns the view never declared -- password hashes, internal flags --
 * out of the RSC payload and out of view-source.
 *
 * The base is readonly because the spec's Data classes are declared
 * `final readonly class RackData extends Data`, and PHP forbids a readonly
 * class from extending a non-readonly one. Every Data object is therefore
 * immutable, and `clone with` is how a modified copy is made.
 *
 * One consequence, recorded in ADR 0004: a readonly class may not declare
 * hooked properties, so a computed Data field is assigned in the constructor
 * rather than derived by a get hook. Models are unaffected.
 */
abstract readonly class Data
{
    /**
     * Builds this Data object from a model, another object or an array:
     * same-named properties map automatically, #[From('...')] names one that
     * differs, and Data-typed and list-of-Data fields map recursively. See
     * Mapper.
     *
     * @param object|array<array-key, mixed> $source
     */
    public static function from(object|array $source): static
    {
        return Mapper::map(static::class, $source);
    }

    /**
     * One Data object per item.
     *
     * @param iterable<object|array<array-key, mixed>> $sources
     * @return list<static>
     */
    public static function collect(iterable $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            $out[] = static::from($source);
        }

        return $out;
    }
}
