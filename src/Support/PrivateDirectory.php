<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * A fresh directory only this user can enter, with a short path -- where a
 * renderer's Unix socket goes.
 *
 * Short, because a socket's path is capped at 104 bytes on macOS and 108 on
 * Linux, counted in full. A socket under the project tree -- run/ in a
 * checkout nested a few directories down, or in a worktree -- passed that
 * limit, and the renderer failed to listen with an error that never said
 * the path was the problem.
 *
 * Private, because RenderChannel refuses a socket in a directory another
 * user owns or can write: whoever could put a socket there would receive
 * the render frame and write the HTML the visitor is served. A new 0700
 * directory under /tmp gives a path that is short and that nobody else can
 * reach into.
 */
final class PrivateDirectory
{
    /** The longest Unix socket path the strictest supported platform takes. */
    public const int MAX_SOCKET_PATH = 104;

    /** The file naming the process a directory belongs to, for removeAbandoned(). */
    public const string OWNER_FILE = 'owner.pid';

    /**
     * Creates the directory and returns its path.
     *
     * The name is a short hash of random bytes, and mkdir() is what claims
     * it: one that already exists -- someone else's, or a symlink planted
     * at a guessable name -- fails the mkdir and another name is tried, so
     * the directory returned is always one this call made.
     *
     * @param string   $prefix the name's first part, so a stray directory says what left it
     * @param string   $base   where to make it; /tmp unless that is missing
     * @param int|null $owner  the process it belongs to, written in it, so that once
     *        that process is gone removeAbandoned() can tell the directory is too
     */
    public static function create(string $prefix = 'tinvar', ?string $base = null, ?int $owner = null): string
    {
        self::assertPrefix($prefix);

        $base = self::base($base);
        if (!is_dir($base) || !is_writable($base)) {
            throw new \RuntimeException("Could not create a private directory under {$base}: it is not a writable directory.");
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $path = "{$base}/{$prefix}-" . substr(hash('sha256', random_bytes(16)), 0, 10);

            // An existing name is the expected way to lose, and is answered
            // by trying another. Checked first so the common case raises no
            // warning; mkdir() still decides, since it is the atomic step.
            if (file_exists($path) || is_link($path) || !mkdir($path, 0700)) {
                continue;
            }

            // mkdir() applies the umask, which can only narrow the mode;
            // chmod makes it exactly 0700 whatever the umask was.
            chmod($path, 0700);

            if ($owner !== null) {
                file_put_contents("{$path}/" . self::OWNER_FILE, (string) $owner);
            }

            return $path;
        }

        throw new \RuntimeException("Could not create a private directory under {$base}.");
    }

    /**
     * Removes the directories made with this prefix whose owner has gone.
     *
     * A process killed outright -- SIGKILL, a crash -- cleans up nothing,
     * and its directory stayed in /tmp for good. Only one that names its
     * owner, belongs to this user, and whose owner is no longer running is
     * removed: one still being set up names no owner yet, and one whose
     * owner is alive is in use.
     *
     * @return list<string> the directories removed
     */
    public static function removeAbandoned(string $prefix, ?string $base = null): array
    {
        self::assertPrefix($prefix);
        if (!\function_exists('posix_kill') || !\function_exists('posix_geteuid')) {
            return [];
        }

        $removed = [];
        foreach (glob(self::base($base) . "/{$prefix}-*", GLOB_ONLYDIR) ?: [] as $directory) {
            // Named as create() names them, so nothing else under /tmp that
            // shares the prefix is touched.
            if (preg_match('/^' . preg_quote($prefix, '/') . '-[0-9a-f]{10}\z/', basename($directory)) !== 1
                || is_link($directory)
                || fileowner($directory) !== posix_geteuid()) {
                continue;
            }

            $ownerFile = "{$directory}/" . self::OWNER_FILE;
            $owner = is_file($ownerFile) && !is_link($ownerFile) ? file_get_contents($ownerFile) : false;
            if (!\is_string($owner) || preg_match('/^[1-9][0-9]{0,9}\z/', $owner) !== 1 || self::running((int) $owner)) {
                continue;
            }

            self::remove($directory);
            $removed[] = $directory;
        }

        return $removed;
    }

    /**
     * Removes the directory and the files in it -- a socket, a log.
     *
     * Not recursive on purpose: nothing this class makes has subdirectories,
     * and a path that turned out to hold some is not one to empty blindly.
     */
    public static function remove(string $directory): void
    {
        clearstatcache();
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "{$directory}/{$entry}";
            // Checked first: a renderer shutting down removes its own
            // socket, and may have just done so.
            if ((!is_dir($path) || is_link($path)) && (file_exists($path) || is_link($path))) {
                unlink($path);
            }
        }

        if (scandir($directory) === ['.', '..']) {
            rmdir($directory);
        }
    }

    private static function assertPrefix(string $prefix): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,15}\z/', $prefix) !== 1) {
            throw new \InvalidArgumentException(
                'A private directory prefix is 1 to 16 lowercase letters, digits and dashes.',
            );
        }
    }

    private static function base(?string $base): string
    {
        return rtrim($base ?? (is_dir('/tmp') ? '/tmp' : sys_get_temp_dir()), '/');
    }

    /** Signal 0 asks without sending anything; EPERM is a process that exists under another user. */
    private static function running(int $pid): bool
    {
        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }
}
