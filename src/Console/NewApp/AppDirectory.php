<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

/**
 * The directory `tinvar new` makes an app in, held so nobody else can
 * change it between the copy and the commands run from it -- bin/tinvar,
 * vendor/ -- which run as the person making the app (ADR 0157).
 *
 * - **The directory is this user's alone to write.** One that is not there
 *   is made here, by a mkdir that fails where something appeared in its
 *   place; one that is there must be empty, this user's, and neither group
 *   nor world writable.
 * - **No directory above it can be swapped by someone else.** Each must be
 *   this user's or root's, and not writable by every user unless sticky,
 *   as /tmp is: whoever may rename an entry of a directory above could put
 *   a tree of their own where the app is. A group-writable one of this
 *   user's own is taken -- a volume's root on macOS, a umask of 002 -- as
 *   the group is theirs to choose.
 *
 * Checked when the answers are, to refuse early, and again by claim() as
 * the app is made, where the directory is then made or taken.
 */
final class AppDirectory
{
    /** Why $path is no place to make an app in, or null. */
    public static function problem(string $path): ?string
    {
        if (is_link($path)) {
            return "{$path} is a link: name the directory itself.";
        }
        if (file_exists($path)) {
            if (!is_dir($path)) {
                return "{$path} is a file.";
            }
            if (array_diff(scandir($path) ?: [], ['.', '..']) !== []) {
                return "{$path} is not empty: tinvar new makes an app only in a new or empty directory.";
            }
            $own = self::owner($path);
            $mode = fileperms($path);
            if (!$own || ($mode !== false && ($mode & 0o022) !== 0)) {
                return "{$path} is " . ($own ? 'writable by other users' : 'not yours') . ': tinvar new makes an app only in a directory nobody else can change under it. Name a new directory, or `chmod go-w` one of yours.';
            }
        }

        // Every directory above it that is there.
        for ($above = \dirname($path); ; $above = \dirname($above)) {
            if (is_dir($above)) {
                $real = realpath($above);
                if ($real === false) {
                    return "{$above} cannot be read.";
                }
                for ($at = $real; ; $at = \dirname($at)) {
                    $mode = fileperms($at);
                    $foreign = !self::owner($at) && fileowner($at) !== 0;
                    $open = $mode !== false && ($mode & 0o002) !== 0 && ($mode & 0o1000) === 0;
                    if ($foreign || $open) {
                        return "{$at}, above {$path}, is " . ($foreign ? 'another user\'s' : 'writable by every user, and not sticky') . ': whoever can rename what is in it could put a tree of their own where the app is. Make the app somewhere only you and root can change.';
                    }
                    if (\dirname($at) === $at) {
                        return null;
                    }
                }
            }
            if (\dirname($above) === $above) {
                return null;
            }
        }
    }

    /**
     * Makes $path, or takes it where it is there and empty, as the class
     * says; its real path.
     *
     * @throws \RuntimeException where it is no place to make an app in
     */
    public static function claim(string $path): string
    {
        $problem = self::problem($path);
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }
        if (!file_exists($path) && !is_link($path)) {
            $parent = \dirname($path);
            if (!is_dir($parent) && !@mkdir($parent, 0o755, true) && !is_dir($parent)) {
                throw new \RuntimeException("Could not make {$parent}.");
            }
            // Fails where anything took the name since it was checked.
            if (!@mkdir($path, 0o755)) {
                throw new \RuntimeException("Could not make {$path}: something is there now.");
            }
            // Made with this process's umask: closed to others' writes whatever it is.
            chmod($path, 0o755);
        }
        $problem = self::problem($path);
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }

        return realpath($path) ?: $path;
    }

    /** Whether $path is this user's; true where the platform cannot say. */
    private static function owner(string $path): bool
    {
        return !\function_exists('posix_geteuid') || fileowner($path) === posix_geteuid();
    }
}
