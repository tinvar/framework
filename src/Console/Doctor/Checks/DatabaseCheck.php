<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Console\Doctor\SafeFix;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\Migrations\Migrator;

/**
 * The database: opened as the application opens it -- SQLite's file, or
 * the Postgres or MySQL server its config names -- asked one statement,
 * and held to its migrations, every one of them run. On a developer's
 * machine `--fix` runs what is pending; on a server that is the deploy's
 * to do, before the new code serves.
 */
final readonly class DatabaseCheck implements DoctorCheck
{
    public function title(): string
    {
        return 'Database';
    }

    public function run(DoctorContext $context): array
    {
        if ($context->connect === null) {
            return [Finding::skipped('The app opens no database through its console.')];
        }

        try {
            $connection = ($context->connect)(false);
            $connection->select('SELECT 1');
        } catch (MigrationRefused $e) {
            return [$this->unmigrated($context, $e->getMessage())];
        } catch (\Throwable $e) {
            // PDO's message names the host and the user, never the password,
            // which is handed to it apart from the DSN.
            return [Finding::problem(
                'The database cannot be reached: ' . self::firstLine($e->getMessage()),
                'Start the server, create the database, and check APP_DATABASE_DRIVER, APP_DATABASE_HOST, APP_DATABASE_PORT, APP_DATABASE_NAME, APP_DATABASE_USER and APP_DATABASE_PASSWORD (or its _FILE); `' . $context->console . ' config` shows each.',
            )];
        }

        if ($context->migrations === null || !is_dir($context->migrations)) {
            return [Finding::passed('The database answers; the app has no migrations.')];
        }
        try {
            $pending = new Migrator($connection, $context->migrations)->pending();
        } catch (\Throwable $e) {
            return [Finding::problem('The migrations cannot be read: ' . self::firstLine($e->getMessage()), 'Fix the migration it names.')];
        }
        if ($pending === []) {
            return [Finding::passed('The database answers, and every migration has run.')];
        }

        return [$this->unmigrated($context, \count($pending) . ' ' . (\count($pending) === 1 ? 'migration has' : 'migrations have') . ' not run: ' . implode(', ', $pending) . '.')];
    }

    private function unmigrated(DoctorContext $context, string $message): Finding
    {
        $production = $context->production();
        if ($production !== null) {
            return Finding::problem($message, "Run `{$context->console} migrate` as part of the deploy, before the new code serves ({$production}: --fix leaves a server's migrations to its deploy).");
        }
        $migrate = $context->migrate;

        return Finding::problem(
            $message,
            "`{$context->console} migrate`, or `{$context->console} doctor --fix`.",
            $migrate === null ? null : new SafeFix('run the pending migrations', static function () use ($migrate): string {
                if ($migrate() !== 0) {
                    throw new \RuntimeException('the migrations failed; see what they said above.');
                }

                return 'ran the pending migrations';
            }),
        );
    }

    private static function firstLine(string $message): string
    {
        return strtok($message, "\n") ?: $message;
    }
}
