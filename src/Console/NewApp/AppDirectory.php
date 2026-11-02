<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

/**
 * The directory `tinvar new` makes an app in, held so nobody else can
 * redirect where it writes or change what it then runs -- bin/tinvar,
 * vendor/ -- as the person making the app (ADR 0157).
 *
 * - **The path is resolved once.** A link on the way to it is followed only
 *   where it is this user's or root's; the app itself is never a link. The
 *   path that is checked is the path that is written to.
 * - **The directory is this user's alone to write.** One that is not there
 *   is made here, by a mkdir that fails where something appeared in its
 *   place; one that is there must be empty, this user's, and neither group
 *   nor world writable.
 * - **No directory above it can be swapped by someone else.** Each must be
 *   this user's or root's. One that is not sticky, as /tmp is, must not be
 *   writable by every user, nor by a group unless it is this user's own --
 *   a volume's root on macOS, a umask of 002 -- whose group is theirs to
 *   choose. Whoever may rename an entry of a directory above could put a
 *   tree of their own where the app is.
 *
 * Checked when the answers are, to refuse early, and again by claim() as
 * the app is made, where the directory is then made or taken.
 */
final class AppDirectory
{
    /**
     * Why $path is no place to make an app in, or null.
     *
     * @param int|null $uid the user making it; this process's by default
     */
    public static function problem(string $path, ?int $uid = null): ?string
    {
        try {
            self::resolve($path, $uid ?? self::uid());

            return null;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Makes $path, or takes it where it is there and empty, as the class
     * says; the resolved path every later step uses.
     *
     * @param int|null $uid the user making it; this process's by default
     * @throws \RuntimeException where it is no place to make an app in
     */
    public static function claim(string $path, ?int $uid = null): string
    {
        $uid ??= self::uid();
        $resolved = self::resolve($path, $uid);
        if (!file_exists($resolved) && !is_link($resolved)) {
            self::make($resolved);
            // Checked again as made: the same path, now there.
            if (self::resolve($resolved, $uid) !== $resolved) {
                throw new \RuntimeException("{$resolved} changed as it was made.");
            }
        }

        return $resolved;
    }

    /**
     * $path with every link on the way followed, each checked, and the
     * directory and those above it held to the rules.
     *
     * @throws \RuntimeException saying what is wrong
     */
    private static function resolve(string $path, ?int $uid): string
    {
        if (!str_starts_with($path, '/')) {
            throw new \RuntimeException("{$path} is not an absolute path.");
        }
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== '' && $p !== '.'));
        if ($parts === [] || \in_array('..', $parts, true)) {
            throw new \RuntimeException("{$path} names no directory an app can be made in: write it without `..`.");
        }
        $name = array_pop($parts);

        // The directories above it, from /, each link on the way checked
        // before it is followed; those not there yet are made by claim().
        $at = '/';
        $missing = [];
        foreach ($parts as $part) {
            $next = ($at === '/' ? '' : $at) . "/{$part}";
            if ($missing !== [] || !file_exists($next) && !is_link($next)) {
                $missing[] = $part;

                continue;
            }
            if (is_link($next)) {
                $owner = lstat($next)['uid'] ?? -1;
                if ($uid !== null && $owner !== $uid && $owner !== 0) {
                    throw new \RuntimeException("{$next}, on the way to {$path}, is a link another user made: it decides where the app goes. Name the directory it leads to.");
                }
                $next = realpath($next) ?: throw new \RuntimeException("{$next}, on the way to {$path}, leads nowhere.");
            }
            if (!is_dir($next)) {
                throw new \RuntimeException("{$next}, on the way to {$path}, is not a directory.");
            }
            $at = $next;
        }
        for ($above = $at; ; $above = \dirname($above)) {
            $problem = self::refusesAbove((int) fileowner($above), (int) fileperms($above), $uid);
            if ($problem !== null) {
                throw new \RuntimeException("{$above}, above {$path}, is {$problem}: whoever can rename what is in it could put a tree of their own where the app is. Make the app somewhere only you and root can change.");
            }
            if (\dirname($above) === $above) {
                break;
            }
        }
        $target = ($at === '/' ? '' : $at) . '/' . implode('/', [...$missing, $name]);

        if (is_link($target)) {
            throw new \RuntimeException("{$path} is a link: name the directory itself.");
        }
        if (file_exists($target)) {
            if (!is_dir($target)) {
                throw new \RuntimeException("{$path} is a file.");
            }
            if (array_diff(scandir($target) ?: [], ['.', '..']) !== []) {
                throw new \RuntimeException("{$path} is not empty: tinvar new makes an app only in a new or empty directory.");
            }
            $own = $uid === null || fileowner($target) === $uid;
            $mode = fileperms($target);
            if (!$own || ($mode !== false && ($mode & 0o022) !== 0)) {
                throw new \RuntimeException("{$path} is " . ($own ? 'writable by other users' : 'not yours') . ': tinvar new makes an app only in a directory nobody else can change under it. Name a new directory, or `chmod go-w` one of yours.');
            }
        }

        return $target;
    }

    /**
     * Why a directory above the app -- of $owner, with $mode -- lets someone
     * other than $uid swap what is in it, or null: another user's (root
     * aside), or, not sticky, writable by every user or by a group the
     * directory is not $uid's own to choose. With $uid null -- no posix --
     * the owner is not compared, and a group's write is refused.
     */
    public static function refusesAbove(int $owner, int $mode, ?int $uid): ?string
    {
        // With no user to compare, only the modes are held: a group's
        // write is then refused, as nobody can say the group is the user's.
        $mine = $uid !== null && $owner === $uid;
        $sticky = ($mode & 0o1000) !== 0;

        return match (true) {
            $uid !== null && !$mine && $owner !== 0 => 'another user\'s',
            !$sticky && ($mode & 0o002) !== 0 => 'writable by every user, and not sticky',
            !$sticky && !$mine && ($mode & 0o020) !== 0 => 'writable by its group, not yours, and not sticky',
            default => null,
        };
    }

    /** Makes $target and whatever above it is missing, each closed to others' writes. */
    private static function make(string $target): void
    {
        $missing = [];
        for ($at = $target; !file_exists($at) && !is_link($at); $at = \dirname($at)) {
            array_unshift($missing, $at);
        }
        foreach ($missing as $at) {
            // Fails where anything took the name since it was checked.
            if (!@mkdir($at, 0o755)) {
                throw new \RuntimeException("Could not make {$at}: something is there now.");
            }
            // Made with this process's umask: closed to others' writes whatever it is.
            chmod($at, 0o755);
        }
    }

    /** This process's user; null where the platform cannot say. */
    private static function uid(): ?int
    {
        return \function_exists('posix_geteuid') ? posix_geteuid() : null;
    }
}
