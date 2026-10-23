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

        self::assertTrusted($file);

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
        self::writePrivately($temporary, $key . "\n");

        $won = @link($temporary, $file);
        unlink($temporary);

        if ($won) {
            return $key;
        }

        $winner = self::read($file)
            ?? throw new \RuntimeException("Cannot read the application key from {$file}.");

        return self::checked($winner, $file);
    }

    /**
     * Creates the file private from its first byte. Writing it and then
     * tightening it left the key readable, under the default umask, by any
     * user on the machine for the moment in between. So: a 0077 umask for
     * the create, exclusive so nothing already there is reused, and 0600
     * confirmed before the key is written.
     */
    private static function writePrivately(string $path, string $contents): void
    {
        $previous = umask(0077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($previous);
        }

        if ($handle === false) {
            throw new \RuntimeException("Cannot create {$path} for the application key.");
        }

        try {
            if (!chmod($path, 0600) || (fileperms($path) & 0077) !== 0) {
                throw new \RuntimeException("Cannot make {$path} private; refusing to write the application key into it.");
            }
            if (fwrite($handle, $contents) !== \strlen($contents)) {
                throw new \RuntimeException("Cannot write the application key to {$path}.");
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw $e;
        }

        fclose($handle);
    }

    /**
     * The key file is believed only if nobody else could have put it there
     * or read it. Someone who can write the directory can plant a key they
     * know -- and then forge every session cookie and token -- and a
     * readable file is a leaked key. A symlink is refused outright: it can
     * point anywhere, at any moment.
     *
     * Skipped where POSIX ownership and modes do not exist (Windows), which
     * relies on the profile's ACLs instead.
     */
    private static function assertTrusted(string $file): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('posix_geteuid')) {
            return;
        }

        $me = posix_geteuid();
        $dir = \dirname($file);

        clearstatcache();

        $dirStat = is_link($dir) || file_exists($dir) ? lstat($dir) : false;
        if ($dirStat !== false) {
            if (is_link($dir) || $dirStat['uid'] !== $me || ($dirStat['mode'] & 0022) !== 0) {
                throw new \RuntimeException(
                    "Refusing the application key directory {$dir}: it must be a directory owned by this user "
                    . 'and writable by no one else.',
                );
            }
        }

        $fileStat = is_link($file) || file_exists($file) ? lstat($file) : false;
        if ($fileStat !== false) {
            if (is_link($file) || $fileStat['uid'] !== $me || ($fileStat['mode'] & 0077) !== 0) {
                throw new \RuntimeException(
                    "Refusing the application key file {$file}: it must be a regular file owned by this user "
                    . 'with mode 0600.',
                );
            }
        }
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
