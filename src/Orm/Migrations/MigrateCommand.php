<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;

/**
 * The migration commands.
 *
 *   tinvar migrate                    run every pending migration, as one batch
 *   tinvar migrate:rollback [--step=N] take back the last batch, or the last N
 *   tinvar migrate:status             each migration, and whether it has run
 *   tinvar migrate:fresh              drop every table and migrate from nothing
 *   tinvar make:migration <name> [--blank]
 *                                     write the migration the models' difference
 *                                     from the database calls for, or an empty one
 *
 * migrate, rollback and fresh wait up to --lock-timeout seconds (60 by
 * default) for another run to finish.
 */
final class MigrateCommand
{
    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    /** Exit status for a run that failed or was refused. */
    public const int FAILED = 1;

    public const array COMMANDS = ['migrate', 'migrate:rollback', 'migrate:status', 'migrate:fresh', 'make:migration'];

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param \Closure(bool): Connection $connect opens the database; given true where the
     *        command may create it (migrate, fresh), false where it must already be there
     * @param list<class-string<Model>> $models what make:migration compares the database with
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     * @param (\Closure(): int)|null $clock the time now, for tests: it names a new migration
     */
    public function __construct(
        private readonly \Closure $connect,
        private readonly string $directory,
        private readonly array $models = [],
        ?\Closure $out = null,
        ?\Closure $err = null,
        ?\Closure $clock = null,
    ) {
        $this->out = $out ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
        $this->clock = $clock ?? time(...);
    }

    /** @param list<string> $arguments what follows the command */
    public function run(string $command, array $arguments): int
    {
        try {
            return match ($command) {
                'migrate' => $this->migrate(self::options($command, $arguments, ['lock-timeout'])),
                'migrate:rollback' => $this->rollback(self::options($command, $arguments, ['step', 'lock-timeout'])),
                'migrate:status' => $this->status(self::options($command, $arguments, [])),
                'migrate:fresh' => $this->fresh(
                    \in_array('--force', $arguments, true),
                    self::options($command, array_values(array_diff($arguments, ['--force'])), ['lock-timeout']),
                ),
                'make:migration' => $this->make($arguments),
                default => throw new \InvalidArgumentException("Unknown command '{$command}'."),
            };
        } catch (\InvalidArgumentException $e) {
            ($this->err)($e->getMessage());

            return self::USAGE;
        } catch (MigrationRefused|MigrationFailed $e) {
            ($this->err)($e->getMessage());

            return self::FAILED;
        } catch (\PDOException $e) {
            ($this->err)('The database refused: ' . $e->getMessage());

            return self::FAILED;
        }
    }

    /** @param array<string, string> $options */
    private function migrate(array $options): int
    {
        $ran = $this->migrator(true, $options)->migrate();
        ($this->out)($ran === [] ? 'Nothing to migrate.' : \sprintf('Ran %d migration(s).', \count($ran)));

        return 0;
    }

    /** @param array<string, string> $options */
    private function rollback(array $options): int
    {
        $steps = null;
        if (isset($options['step'])) {
            $steps = ctype_digit($options['step']) && (int) $options['step'] > 0
                ? (int) $options['step']
                : throw new \InvalidArgumentException('--step takes a number of migrations, 1 or more.');
        }

        $rolledBack = $this->migrator(false, $options)->rollback($steps);
        ($this->out)($rolledBack === [] ? 'Nothing to roll back.' : \sprintf('Rolled back %d migration(s).', \count($rolledBack)));

        return 0;
    }

    /** @param array<string, string> $options */
    private function status(array $options): int
    {
        $migrator = $this->migrator(false, $options);
        $rows = $migrator->status();
        if ($rows === []) {
            ($this->out)("No migrations in {$this->directory}.");
        } else {
            ($this->out)(\sprintf('%-4s %-6s %s', 'Ran', 'Batch', 'Migration'));
            foreach ($rows as $row) {
                ($this->out)(rtrim(\sprintf(
                    '%-4s %-6s %s%s',
                    $row['batch'] === null ? 'no' : 'yes',
                    $row['batch'] ?? '',
                    $row['name'],
                    $row['file'] ? '' : '  (recorded as run; its file is gone)',
                )));
            }
        }

        // A tenant table the policy does not hold is one a forgotten
        // predicate reads across tenants; worth saying wherever it is seen.
        $dialect = $migrator->dialect();
        $connection = $migrator->connection();
        foreach ($this->models as $model) {
            $meta = Metadata::for($model);
            if ($meta->tenantColumn !== null
                && $dialect->hasTable($connection, $meta->table)
                && !$dialect->hasTenantPolicy($connection, $meta->table)) {
                ($this->err)("warning: {$meta->table} is #[TenantScoped] and has no row-level security policy; `tinvar make:migration` writes the migration that adds it.");
            }
        }

        return 0;
    }

    /** @param array<string, string> $options */
    private function fresh(bool $force, array $options): int
    {
        // Every table dropped, every row with it: never in production, and
        // nowhere without saying so. A command typed into the wrong shell,
        // or a script pointed at the wrong DSN, must stop here.
        foreach (['TINVAR_ENV', 'APP_ENV'] as $variable) {
            if (getenv($variable) === 'production') {
                throw new MigrationRefused("migrate:fresh drops every table, and {$variable} says this is production. It is refused here.");
            }
        }
        if (!$force) {
            throw new MigrationRefused('migrate:fresh drops every table and every row in them. Run it again with --force to do that.');
        }

        $ran = $this->migrator(true, $options)->fresh();
        ($this->out)(\sprintf('Dropped every table and ran %d migration(s).', \count($ran)));

        return 0;
    }

    /** @param list<string> $arguments */
    private function make(array $arguments): int
    {
        $blank = false;
        $name = null;
        foreach ($arguments as $argument) {
            if ($argument === '--blank') {
                $blank = true;
            } elseif ($name === null && !str_starts_with($argument, '-')) {
                $name = $argument;
            } else {
                throw new \InvalidArgumentException("Unknown argument '{$argument}'. Usage: tinvar make:migration <name> [--blank]");
            }
        }
        if ($name === null || preg_match('/^[a-z][a-z0-9_]{0,100}\z/', $name) !== 1) {
            throw new \InvalidArgumentException('Usage: tinvar make:migration <name> [--blank], the name in snake_case: add_notes_to_orders.');
        }

        $now = ($this->clock)();
        $path = rtrim($this->directory, '/') . '/' . gmdate('Y_m_d_His', $now) . "_{$name}.php";
        if (file_exists($path)) {
            throw new MigrationRefused("{$path} already exists.");
        }

        if ($blank) {
            $source = MigrationGenerator::blank();
        } else {
            $migrator = $this->migrator(false, []);

            // The diff is against the database as it is; one behind its
            // migrations would be given a migration repeating them.
            $pending = is_dir($this->directory) ? $migrator->pending() : [];
            if ($pending !== []) {
                throw new MigrationRefused(\sprintf(
                    '%d migration(s) have not run here (%s); migrate first, or this one would repeat them.',
                    \count($pending),
                    implode(', ', $pending),
                ));
            }

            $generator = new MigrationGenerator($migrator->connection(), $migrator->dialect());
            $source = $generator->generate($this->models, gmdate('Y-m-d', $now));
            foreach ($generator->warnings as $warning) {
                ($this->err)("warning: {$warning}");
            }
            if ($source === null) {
                ($this->out)('The database already has everything the models describe; nothing written. For a backfill or a change the models cannot say: tinvar make:migration <name> --blank');

                return 0;
            }
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new MigrationRefused("Could not create {$this->directory}.");
        }
        file_put_contents($path, $source);
        ($this->out)("wrote {$path}");

        return 0;
    }

    /** @param array<string, string> $options */
    private function migrator(bool $create, array $options): Migrator
    {
        $timeout = Migrator::LOCK_TIMEOUT;
        if (isset($options['lock-timeout'])) {
            $timeout = is_numeric($options['lock-timeout']) && (float) $options['lock-timeout'] >= 0
                ? (float) $options['lock-timeout']
                : throw new \InvalidArgumentException('--lock-timeout takes a number of seconds.');
        }

        return new Migrator(
            ($this->connect)($create),
            $this->directory,
            lockTimeout: $timeout,
            log: $this->out,
        );
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private static function options(string $command, array $arguments, array $allowed): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)=(.*)\z/', $argument, $m) !== 1 || !\in_array($m[1], $allowed, true)) {
                $usage = implode(' ', array_map(static fn (string $option): string => "[--{$option}=…]", $allowed));
                throw new \InvalidArgumentException("Unknown argument '{$argument}'. Usage: tinvar {$command}" . ($usage === '' ? '' : " {$usage}"));
            }
            $options[$m[1]] = $m[2];
        }

        return $options;
    }
}
