<?php

declare(strict_types=1);

namespace Tinvar\Staging;

use Tinvar\Config\FrameworkConfig;
use Tinvar\Support\AppKey;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\Encrypter;

/**
 * `tinvar staging:import`: an application's production database into its
 * staging database, through its ScrubPlan (ADR 0090).
 *
 *   tinvar staging:import --from=PRODUCTION --to=STAGING --staging-key-file=PATH [--tenant=ID]
 *
 * Run on production's side, under production's profile, with production's
 * key -- the application's, as its server has it -- which it reads with and
 * never writes anywhere. Staging's key is TINVAR_STAGING_APP_KEY or the file
 * --staging-key-file names, which must already be there: one generated here
 * would be a key staging does not have. It is bound to the staging profile
 * as staging's server binds it, so the values sealed here are the ones
 * staging opens.
 *
 * What --from and --to name is the application's to open ($open): a SQLite
 * file for the shop, a DSN elsewhere.
 */
final class StagingImportCommand
{
    public const int USAGE = 64;

    public const int REFUSED = 65;

    public const string STAGING_KEY_ENV = 'TINVAR_STAGING_APP_KEY';

    private const array OPTIONS = ['from', 'to', 'staging-key-file', 'tenant'];

    private const string SYNOPSIS = 'tinvar staging:import --from=PRODUCTION --to=STAGING --staging-key-file=PATH [--tenant=ID]';

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /**
     * @param \Closure(): string $productionKey the application's own key, as its server resolves it
     * @param \Closure(string): \PDO $open opens the database --from or --to names
     * @param array<string, string>|null $env the environment; the process's own by default
     */
    public function __construct(
        private readonly ScrubPlan $plan,
        private readonly \Closure $productionKey,
        private readonly \Closure $open,
        private readonly ?array $env = null,
        ?\Closure $out = null,
        ?\Closure $err = null,
    ) {
        $this->out = $out ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)=(.+)\z/s', $argument, $m) !== 1 || !\in_array($m[1], self::OPTIONS, true)) {
                ($this->err)("Unknown argument '{$argument}'. " . self::SYNOPSIS);

                return self::USAGE;
            }
            $options[$m[1]] = $m[2];
        }
        if (!isset($options['from'], $options['to'])) {
            ($this->err)(self::SYNOPSIS);

            return self::USAGE;
        }

        try {
            $env = $this->env ?? array_filter(getenv(), \is_string(...));
            // The process's own as the framework's config read it; a given
            // environment, a test's, as BuildProfile reads it.
            $profile = $this->env === null ? FrameworkConfig::load()->buildProfile() : BuildProfile::fromEnvironment($env);
            if ($profile !== BuildProfile::PRODUCTION) {
                throw new ImportRefused(
                    "staging:import runs on production's side, with production's key, and this process's profile is {$profile}.",
                );
            }

            $production = ($this->productionKey)();
            $staging = $this->stagingKey($options['staging-key-file'] ?? null, $env);
            // Bound, the two would differ anyway; but staging holding
            // production's key is production's secret in staging already.
            if (hash_equals($production, $staging)) {
                throw new ImportRefused('Staging\'s key is production\'s TINVAR_APP_KEY. Give staging a key of its own first.');
            }

            $import = new StagingImport(
                $this->plan,
                new Encrypter($production),
                new Encrypter(AppKey::forProfile($staging, BuildProfile::STAGING)),
            );
            $written = $import->run(($this->open)($options['from']), ($this->open)($options['to']), $options['tenant'] ?? null);
        } catch (ImportRefused|\RuntimeException|\InvalidArgumentException|\PDOException $e) {
            ($this->err)($e->getMessage());

            return self::REFUSED;
        }

        foreach ($written as $table => $rows) {
            ($this->out)(\in_array($table, $this->plan->dropped(), true) ? "  {$table}: dropped" : "  {$table}: {$rows} rows");
        }
        ($this->out)('Imported into staging, scrubbed and re-encrypted under its key.');

        return 0;
    }

    /**
     * Staging's own key, unbound: TINVAR_STAGING_APP_KEY, or a file that is
     * already there. Never generated: a key made here is one staging lacks.
     *
     * @param array<string, string> $env
     */
    private function stagingKey(?string $file, array $env): string
    {
        // The process's own as the framework's config read it, a Secret
        // until here; a given environment, a test's, as it says.
        $given = trim($this->env === null ? (string) FrameworkConfig::load()->stagingAppKey?->reveal() : ($env[self::STAGING_KEY_ENV] ?? ''));
        if ($given !== '') {
            if (\strlen($given) < AppKey::MIN_LENGTH) {
                throw new ImportRefused(self::STAGING_KEY_ENV . ' must be at least ' . AppKey::MIN_LENGTH . ' bytes.');
            }

            return $given;
        }

        if ($file === null) {
            throw new ImportRefused('Name staging\'s key: --staging-key-file=PATH, or ' . self::STAGING_KEY_ENV . '.');
        }
        if (!str_starts_with($file, '/') || !is_file($file)) {
            throw new ImportRefused("No key file at {$file}: staging's key is a file staging already has, named absolutely.");
        }

        // Its ownership and mode held as the application's own key file's are.
        return AppKey::resolve(self::STAGING_KEY_ENV, $file);
    }
}
