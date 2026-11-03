<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Console\Doctor\SafeFix;

/**
 * Who may read and write what holds a secret or the data: the app key's
 * and the probe token's files, and the state directory they are in, this
 * user's alone (AppKey writes them so, and refuses one another user
 * could have planted); the SQLite database and the files beside it,
 * readable by no one else and writable by this user; `.env`, writable by
 * no one else, since what it sets is believed.
 */
final readonly class PermissionsCheck implements DoctorCheck
{
    public function title(): string
    {
        return 'File permissions';
    }

    public function run(DoctorContext $context): array
    {
        $findings = [];
        $owner = \function_exists('posix_geteuid') ? posix_geteuid() : null;

        foreach (array_filter([$context->keyFile, $context->probeTokenFile]) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $findings = [...$findings, ...self::private($file, $owner, 'it holds a secret')];
            $findings = [...$findings, ...self::private(\dirname($file), $owner, 'it holds the app\'s secrets')];
        }

        $database = self::database($context);
        $driver = strtolower($context->variable('APP_DATABASE_DRIVER') ?? 'sqlite');
        if ($driver === 'sqlite' && $database !== null && $database !== ':memory:') {
            if (is_file($database)) {
                $findings = [...$findings, ...self::unreadableByOthers($database, $context->production())];
                if (!is_writable($database)) {
                    $findings[] = Finding::problem("{$database} is not writable by this user: every write fails.", "chown it to the user the server runs as, and `chmod 600 {$database}`.");
                }
            }
            $directory = \dirname($database);
            if (is_dir($directory) && !is_writable($directory)) {
                $findings[] = Finding::problem("{$directory} is not writable by this user: SQLite keeps its write-ahead log and the throttle and metrics files beside the database.", "chown {$directory} to the user the server runs as.");
            }
        }

        $env = "{$context->root}/.env";
        if (is_file($env) && (fileperms($env) & 0o022) !== 0) {
            $findings[] = Finding::problem("{$env} is writable by other users, and what it sets is believed.", "`chmod go-w {$env}`.");
        }

        return $findings === [] ? [Finding::passed('The secrets\' files and the database are this user\'s alone.')] : $findings;
    }

    /**
     * The SQLite file the application opens, as its console names it; the
     * skeleton's APP_DATABASE, or its default, where the context names none.
     * Null where naming it is refused -- a config the Config check reports.
     */
    private static function database(DoctorContext $context): ?string
    {
        if ($context->database === null) {
            return $context->variable('APP_DATABASE') ?? "{$context->root}/database/app.sqlite";
        }
        try {
            return ($context->database)();
        } catch (\RuntimeException) {
            return null;
        }
    }

    /** @return list<Finding> */
    private static function private(string $path, ?int $owner, string $why): array
    {
        $findings = [];
        $mode = fileperms($path) & 0o777;
        if (($mode & 0o077) !== 0) {
            $findings[] = Finding::problem(\sprintf('%s is mode %04o, open to other users, and %s.', $path, $mode, $why), \sprintf('`chmod %s %s`.', is_dir($path) ? '700' : '600', $path));
        }
        $fileOwner = fileowner($path);
        if ($owner !== null && $fileOwner !== false && $fileOwner !== $owner) {
            $findings[] = Finding::problem("{$path} belongs to another user (uid {$fileOwner}), and {$why}: AppKey refuses one it did not make.", "Make it this user's (`chown`), or delete it and let `key:generate` make it again.");
        }

        return $findings;
    }

    /**
     * The database open to other users: a problem on a server, where they
     * are not the developer's; on a developer's machine, where SQLite made
     * the file under the shell's umask, a warning --fix closes.
     *
     * @return list<Finding>
     */
    private static function unreadableByOthers(string $path, ?string $production): array
    {
        $mode = fileperms($path) & 0o777;
        if (($mode & 0o007) === 0) {
            return [];
        }
        $message = \sprintf('%s is mode %04o: any user on this machine may read every row.', $path, $mode);
        if ($production !== null) {
            return [Finding::problem($message, "`chmod 600 {$path}`, as the user the server runs as.")];
        }

        return [Finding::warning($message, "`chmod 600 {$path}`, or `doctor --fix`.", new SafeFix("close {$path} to other users", static function () use ($path): string {
            if (!chmod($path, 0o600)) {
                throw new \RuntimeException("chmod refused; run `chmod 600 {$path}` yourself.");
            }

            return "made {$path} this user's alone (0600)";
        }))];
    }
}
