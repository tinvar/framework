<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * The application key: from the environment if it is set there, otherwise
 * from a key file, generated into that file the first time.
 *
 * Persisted, not merely generated. Under php -S every request is a fresh
 * process, and a worker pool is several; a key made up per process would
 * sign a token in one that no other could verify. So the first process to
 * need a key writes one, and every later process -- and every restart --
 * reads that one.
 *
 * Written to a file, not exported into the environment. An exported
 * variable is inherited by every child process, and the renderer is exactly
 * the process that must never hold it: "the renderer gets no database
 * credentials or secrets". The file is created 0600.
 */
final class AppKey
{
    /** Bytes of entropy in a generated key. */
    public const int BYTES = 32;

    /** The shortest key accepted from anywhere: any token is an offline oracle for it. */
    public const int MIN_LENGTH = 32;

    /**
     * @param string $envName the variable that, if set, wins
     * @param string $file    where a generated key is kept
     */
    public static function resolve(string $envName, string $file): string
    {
        $fromEnv = getenv($envName);
        if (\is_string($fromEnv) && $fromEnv !== '') {
            return self::checked($fromEnv, "\${$envName}");
        }

        $existing = self::read($file);
        if ($existing !== null) {
            return self::checked($existing, $file);
        }

        return self::generate($file);
    }

    private static function generate(string $file): string
    {
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir} to store the application key.");
        }

        $key = base64_encode(random_bytes(self::BYTES));

        // Written in full under a private name, then linked into place.
        // link() fails if the target exists, so of two processes generating
        // at once exactly one key wins, and the other -- finding the file
        // there -- reads that one rather than signing with its own. A plain
        // write could leave a reader seeing a half-written file.
        $temporary = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $key . "\n") === false) {
            throw new \RuntimeException("Cannot write the application key under {$dir}.");
        }
        chmod($temporary, 0600);

        $won = @link($temporary, $file);
        unlink($temporary);

        if ($won) {
            return $key;
        }

        $winner = self::read($file)
            ?? throw new \RuntimeException("Cannot read the application key from {$file}.");

        return self::checked($winner, $file);
    }

    private static function read(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);

        return $contents === false ? null : trim($contents);
    }

    /**
     * A short key is refused wherever it came from -- including a file
     * someone edited by hand. It is never replaced silently: a new key would
     * invalidate every token in flight, and an unexpected one is a reason to
     * stop and look.
     */
    private static function checked(string $key, string $source): string
    {
        if (\strlen($key) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(
                "The application key from {$source} must be at least " . self::MIN_LENGTH . ' bytes.',
            );
        }

        return $key;
    }
}
