<?php

declare(strict_types=1);

namespace Tinvar\Generators;

/**
 * A generator declining to write: before it has written anything, for a
 * reason the developer can act on -- a file already there, or nowhere the
 * application's autoloading says a class would go.
 *
 * A name or a path that is not one -- `../x`, `Racks/List`, `/racks/{1}` --
 * is a usage error instead (\InvalidArgumentException), as for every other
 * command.
 */
final class GenerationRefused extends \RuntimeException
{
    /** @param list<string> $paths */
    public static function exists(array $paths): self
    {
        return new self(
            (\count($paths) === 1 ? "{$paths[0]} already exists" : 'These already exist: ' . implode(', ', $paths))
            . '. Nothing was written: a generator never replaces a file. Move it, or choose another name.',
        );
    }

    public static function unmapped(string $class, string $composer): self
    {
        return new self(
            "Nothing in {$composer}'s autoload or autoload-dev psr-4 maps {$class} to a directory, so it would "
            . 'be written where nothing loads it. Nothing was written.',
        );
    }

    public static function outside(string $path, string $within): self
    {
        return new self("{$path} is not inside {$within}. Nothing was written: a generator writes only inside the application.");
    }
}
