<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

/**
 * The directory `tinvar new` makes an app in, held so nobody else can
 * redirect where it writes or change what it then runs -- tinvar,
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
 *   writable by every user, nor by a group -- unless it is this user's own
 *   and its group provably theirs alone: a personal group, named as they
 *   are, that no other user is in by any reckoning the system gives
 *   (personalGroup()). A shared group -- macOS's staff, which holds every
 *   local user -- is refused, as is anything that cannot be read. Whoever
 *   may rename an entry of a directory above could put a tree of their own
 *   where the app is.
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
            $owner = (int) fileowner($above);
            $mode = (int) fileperms($above);
            // Whether its group is the user's alone is read only where it decides.
            $personal = $uid !== null && $owner === $uid && ($mode & 0o1022) === 0o020
                && self::personalGroup((int) filegroup($above), $uid);
            $problem = self::refusesAbove($owner, $mode, $uid, $personal);
            if ($problem !== null) {
                $fix = $uid !== null && $owner === $uid ? "Run `chmod g-w,o-w {$above}`, or make" : 'Make';
                throw new \RuntimeException("{$above}, above {$path}, is {$problem}: whoever can rename what is in it could put a tree of their own where the app is. {$fix} the app somewhere only you and root can change.");
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
     * aside), or, not sticky, writable by every user or by a group. A
     * group's write is taken only on a directory of $uid's own whose group
     * is provably theirs alone ($personal, from personalGroup()); with $uid
     * null -- no posix -- it is refused.
     */
    public static function refusesAbove(int $owner, int $mode, ?int $uid, bool $personal = false): ?string
    {
        $mine = $uid !== null && $owner === $uid;
        $sticky = ($mode & 0o1000) !== 0;
        $grouped = !$sticky && ($mode & 0o020) !== 0;

        return match (true) {
            $uid !== null && !$mine && $owner !== 0 => 'another user\'s',
            !$sticky && ($mode & 0o002) !== 0 => 'writable by every user, and not sticky',
            $grouped && !$mine => 'writable by its group, not yours, and not sticky',
            $grouped && !$personal => 'writable by its group, which is not provably yours alone, and not sticky',
            default => null,
        };
    }

    /**
     * Whether group $gid is provably $uid's alone, read from this system.
     * Only a personal group can be: named as the user is, listing no member
     * but them (and root), and holding no other user -- as their primary
     * group in /etc/passwd or, on macOS, by the directory service's own
     * reckoning (`dsmemberutil checkmembership` for every local user). A
     * shared group -- staff, users, wheel -- never is, nor one the system
     * cannot be asked about: anything not read is no. Users only a network
     * directory knows (LDAP, NIS) are not asked about off macOS, which a
     * personal group named as its user leaves no room for in practice.
     */
    public static function personalGroup(int $gid, int $uid): bool
    {
        if (!\function_exists('posix_getgrgid') || !\function_exists('posix_getpwuid')) {
            return false;
        }
        $user = posix_getpwuid($uid);
        $group = posix_getgrgid($gid);
        if (!\is_array($user) || !\is_array($group) || !self::personal($group, $user, [])) {
            return false;
        }

        return self::personal($group, $user, self::othersIn($group['name'], $gid, $user['name']));
    }

    /**
     * Whether $group is $user's personal group: named as they are, listing
     * no member but them and root, and $others -- the other users the
     * system finds in it -- none. Anything unknown (false, null) is no.
     *
     * @param array{name?: string, members?: list<string>}|false $group as posix_getgrgid() gives it
     * @param array{name?: string}|false $user as posix_getpwuid() gives it
     * @param list<string>|null $others
     */
    public static function personal(array|false $group, array|false $user, ?array $others): bool
    {
        if ($group === false || $user === false || $others === null) {
            return false;
        }
        $name = $user['name'] ?? '';
        if ($name === '' || ($group['name'] ?? null) !== $name) {
            return false;
        }

        return array_diff($group['members'] ?? [], [$name, 'root']) === [] && array_diff($others, [$name, 'root']) === [];
    }

    /**
     * The users other than $user the system finds in group $group: on
     * macOS, every local user `dsmemberutil` does not say is outside it,
     * however it reckons membership; elsewhere, every user /etc/passwd
     * gives it as their primary group. Null where that cannot be read.
     *
     * @return list<string>|null
     */
    private static function othersIn(string $group, int $gid, string $user): ?array
    {
        $others = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            $listed = self::output(['/usr/bin/dscl', '.', '-list', '/Users']);
            if ($listed === null) {
                return null;
            }
            foreach (preg_split('/\R/', trim($listed)) ?: [] as $name) {
                $name = trim($name);
                if ($name === '' || $name === $user || $name === 'root') {
                    continue;
                }
                $answer = self::output(['/usr/bin/dsmemberutil', 'checkmembership', '-U', $name, '-G', $group]);
                if ($answer === null || !str_contains($answer, 'is not a member')) {
                    $others[] = $name;
                }
            }

            return $others;
        }
        $passwd = @file_get_contents('/etc/passwd');
        if ($passwd === false) {
            return null;
        }
        foreach (preg_split('/\R/', $passwd) ?: [] as $line) {
            $fields = explode(':', $line);
            if (\count($fields) >= 4 && $fields[3] === (string) $gid && !str_starts_with($fields[0], '#') && $fields[0] !== $user) {
                $others[] = $fields[0];
            }
        }

        return $others;
    }

    /**
     * What $command prints, or null where it cannot run or fails.
     *
     * @param list<string> $command
     */
    private static function output(array $command): ?string
    {
        if (!is_executable($command[0])) {
            return null;
        }
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return proc_close($process) === 0 && $out !== false ? $out : null;
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
