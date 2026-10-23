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
        if ($file === '' || str_ends_with($file, '/') || is_dir($file)) {
            throw new \InvalidArgumentException("The application key file must be a file path, not a directory: {$file}.");
        }

        $fromEnv = getenv($envName);
        if (\is_string($fromEnv) && self::normalised($fromEnv) !== '') {
            return self::checked(self::normalised($fromEnv), "\${$envName}");
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
        self::assertTrusted($file);

        // One process generates at a time; the rest wait and then read what
        // it wrote. A lock, not link(): link() fails outright on filesystems
        // without hard links -- FAT, exFAT, some network and container
        // mounts -- and every start there failed.
        $lock = self::openPrivately($dir . '/.' . basename($file) . '.lock', 'c');
        try {
            // Some network and FUSE mounts refuse locks. Generating without
            // one there can only race another first start, and the re-read
            // after the rename settles every process on whichever key landed.
            $locked = flock($lock, LOCK_EX);

            $existing = self::read($file);
            if ($existing !== null) {
                return self::checked($existing, $file);
            }

            self::sweepTemporaries($dir, basename($file));

            $key = base64_encode(random_bytes(self::BYTES));

            // Written in full under a private name, flushed to disk, then
            // renamed into place: a reader never sees a half-written file.
            // (The rename itself is not flushed: after a power cut the key
            // can be missing and is generated afresh -- never half there.)
            $temporary = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(6));
            self::writePrivately($temporary, $key . "\n");
            if (!@rename($temporary, $file)) {
                @unlink($temporary);
                throw new \RuntimeException("Cannot move the application key into {$file}.");
            }

            return $locked ? $key : self::checked(self::read($file) ?? $key, $file);
        } finally {
            if ($locked ?? false) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    /**
     * Temporary key files a crash left behind, older than a minute. Each
     * holds a key, so they are not left to accumulate.
     */
    private static function sweepTemporaries(string $dir, string $name): void
    {
        foreach (glob($dir . '/.' . $name . '.*') ?: [] as $leftover) {
            if (str_ends_with($leftover, '.lock')) {
                continue;
            }
            $modified = @filemtime($leftover);
            if ($modified !== false && $modified < time() - 60) {
                @unlink($leftover);
            }
        }
    }

    /**
     * Opens a file created private from its first byte -- under a 0077
     * umask, so it is never readable by others, not even for an instant.
     *
     * @param 'x'|'c' $mode exclusive create, or create-or-open
     * @return resource
     */
    private static function openPrivately(string $path, string $mode)
    {
        $previous = umask(0077);
        try {
            $handle = @fopen($path, $mode);
        } finally {
            umask($previous);
        }

        if ($handle === false) {
            throw new \RuntimeException("Cannot create {$path} for the application key.");
        }

        return $handle;
    }

    /**
     * Creates the file private from its first byte. Writing it and then
     * tightening it left the key readable, under the default umask, by any
     * user on the machine for the moment in between. So: a 0077 umask for
     * the create, exclusive so nothing already there is reused, and 0600
     * confirmed before the key is written.
     */
    private static function writePrivately(string $path, #[\SensitiveParameter] string $contents): void
    {
        $handle = self::openPrivately($path, 'x');

        try {
            if (!chmod($path, 0600) || (fileperms($path) & 0077) !== 0) {
                throw new \RuntimeException("Cannot make {$path} private; refusing to write the application key into it.");
            }
            if (fwrite($handle, $contents) !== \strlen($contents) || !fflush($handle) || !fsync($handle)) {
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
     * or read it: whoever can write the directory can plant a key they know
     * -- and then forge every session cookie and token -- and a file anyone
     * can read is a leaked key.
     *
     * Judged by what the paths really are. A symlinked state directory (a
     * dotfiles setup) or a key file that is a symlink to a mounted secret
     * (Kubernetes) is fine; what it resolves to is checked instead. Owned by
     * this user or by root -- a secret mounted by the platform is root's --
     * and writable by nobody else; the file readable by nobody outside its
     * group, so a key shared with the web server's group still works.
     *
     * Skipped where POSIX ownership and modes do not exist (Windows), which
     * relies on the profile's ACLs instead.
     */
    private static function assertTrusted(string $file): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('posix_geteuid')) {
            return;
        }

        $trusted = [posix_geteuid(), 0];
        clearstatcache();

        if (is_link($file) && !file_exists($file)) {
            throw new \RuntimeException("Refusing the application key file {$file}: it is a symlink to nothing.");
        }

        $dir = realpath(\dirname($file));
        if ($dir !== false) {
            $stat = stat($dir);
            if ($stat === false || !\in_array($stat['uid'], $trusted, true) || ($stat['mode'] & 0022) !== 0) {
                throw new \RuntimeException(
                    "Refusing the application key directory {$dir}: it must be owned by this user or root and "
                    . 'writable by no one else. Or set TINVAR_APP_KEY.',
                );
            }
        }

        $real = realpath($file);
        if ($real !== false) {
            $stat = stat($real);
            if ($stat === false || !is_file($real) || !\in_array($stat['uid'], $trusted, true) || ($stat['mode'] & 0026) !== 0) {
                throw new \RuntimeException(
                    "Refusing the application key file {$real}: it must be a regular file owned by this user or "
                    . 'root, writable by no one else and readable by no one outside its group. Or set TINVAR_APP_KEY.',
                );
            }
        }
    }

    private static function read(string $file): ?string
    {
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return null;
        }

        // There but unreadable is an error to name, not a cue to generate:
        // generating would only fail at the same place, after a PHP warning
        // that under the CLI server lands in the response.
        $contents = is_readable($file) ? @file_get_contents($file) : false;
        if ($contents === false) {
            throw new \RuntimeException("Cannot read the application key from {$file}.");
        }

        return self::normalised($contents);
    }

    /**
     * The key without surrounding whitespace or a byte-order mark, whichever
     * source it came from. Treated differently, moving a key from the file
     * to the environment -- a docker env file, a mounted secret -- changed
     * the signing key and invalidated every token in flight.
     */
    private static function normalised(#[\SensitiveParameter] string $key): string
    {
        $key = trim($key);

        return trim(str_starts_with($key, "\u{FEFF}") ? substr($key, 3) : $key);
    }

    /**
     * A short key is refused wherever it came from -- including a file
     * someone edited by hand. It is never replaced silently: a new key would
     * invalidate every token in flight, and an unexpected one is a reason to
     * stop and look.
     *
     * The key is a sensitive parameter here and everywhere it is passed:
     * this method exists to throw, and a trace of that throw printed the key
     * it was refusing -- in full through getTrace(), and its first bytes
     * through getTraceAsString().
     */
    private static function checked(#[\SensitiveParameter] string $key, string $source): string
    {
        // Visible ASCII only. A UTF-16 file, a stray NUL or an editor's
        // encoding passed the length check and silently became a different
        // key from the one meant.
        if (preg_match('/^[\x21-\x7e]*\z/', $key) !== 1) {
            throw new \InvalidArgumentException(
                "The application key from {$source} must be printable ASCII, such as `openssl rand -base64 32`.",
            );
        }

        if (\strlen($key) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(
                "The application key from {$source} must be at least " . self::MIN_LENGTH . ' bytes'
                . ($source[0] === '$' ? '.' : '; delete the file to have a new one generated.'),
            );
        }

        return $key;
    }
}
