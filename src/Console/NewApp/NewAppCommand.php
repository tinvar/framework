<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

use Tinvar\Console\Deploy\DeployTarget;
use Tinvar\Console\Kits\KitDeclaration;
use Tinvar\Console\Prompts;
use Tinvar\Console\Secrets\SecretFiles;
use Tinvar\Mail\MailProvider;
use Tinvar\Orm\DatabaseDriver;
use Tinvar\Support\StateFile;

/**
 * `tinvar new <directory>` (ADR 0150): a working application, made from
 * the skeleton and shaped by what it asks -- the database, tenancy and how
 * a tenant is found, the mail provider, the kits (accounts), the deploy
 * target -- then installed, migrated and seeded, its secrets generated
 * into files outside it, and the next steps printed.
 *
 *     bin/tinvar new ../my-app
 *     bin/tinvar new ../my-app --database=postgres --tenancy=subdomain --mail=postmark --deploy=fly -n
 *
 * Each question is a Prompts question; with nobody there each takes its
 * flag, or its default (SQLite, no tenancy, mail to a log, no kit, no
 * deployment), so the command is scriptable. The skeleton comes from this
 * repository's checkout while nothing is published, and from Packagist
 * once tinvar/skeleton is (Skeletons::choose()).
 *
 * Refuses a directory that is not empty, and leaves it as it was.
 */
final class NewAppCommand
{
    public const string USAGE = 'new <directory> [--database=sqlite|postgres|mysql] [--database-host=HOST] [--database-port=PORT] [--database-name=NAME] [--database-user=USER] [--tenancy=off|subdomain|domain] [--mail=log|smtp|ses|postmark|mailgun|resend|sendgrid] [--accounts|--no-accounts] [--kit=PACKAGE] [--deploy=fly|render|railway|vps-systemd|vps-compose|none] [--from=monorepo|packagist] [--no-install]';

    /** What the new application's commands are never handed from this process's environment: another app's settings. */
    public const array NOT_INHERITED = ['TINVAR_APP_KEY', 'TINVAR_APP_KEY_FILE', 'TINVAR_PROBE_TOKEN', 'TINVAR_PROBE_TOKEN_FILE', 'TINVAR_PROBE_TRUST_LOOPBACK', 'APP_DATABASE', 'APP_DATABASE_DRIVER', 'APP_DATABASE_HOST', 'APP_DATABASE_PORT', 'APP_DATABASE_NAME', 'APP_DATABASE_USER', 'APP_DATABASE_PASSWORD', 'APP_DATABASE_PASSWORD_FILE', 'TINVAR_ENV', 'TINVAR_PROFILE', 'TINVAR_BUILD_DIR', 'COMPOSER'];

    /**
     * @param \Closure(string): void $out
     * @param \Closure(string): void $err
     * @param (\Closure(?string): SkeletonSource)|null $sources chooses the source from --from; Skeletons::choose() by default
     */
    public function __construct(
        private readonly \Closure $out,
        private readonly \Closure $err,
        private readonly ?Processes $processes = null,
        private readonly ?\Closure $sources = null,
        private readonly string $cwd = '',
    ) {}

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $flags = NewAppFlags::parse($arguments);
            $source = $this->sources !== null ? ($this->sources)($flags->option('from')) : Skeletons::choose($flags->option('from'));
            $processes = $this->processes ?? new Processes($this->out);
            $answers = $this->answers($flags, $source, $processes);
        } catch (\InvalidArgumentException $e) {
            ($this->err)($e->getMessage() . "\nUsage: tinvar " . self::USAGE . "\n");

            return 64;
        }

        try {
            return $this->make($answers, $source, $processes);
        } catch (\RuntimeException $e) {
            ($this->err)("\n" . $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * Writes and installs the application.
     *
     * @throws \RuntimeException where a step fails, saying which
     */
    public function make(NewAppAnswers $answers, SkeletonSource $source, Processes $processes): int
    {
        $directory = $answers->directory;
        ($this->out)("Making {$answers->name()} in {$directory}, from {$source->describe()}.\n");

        $extra = [...($answers->tenancy !== TenantLookup::Off ? ['tinvar/tenancy'] : []), ...$answers->kits];
        $source->fetch($directory, $extra, $processes);
        $directory = realpath($directory) ?: $directory;

        // The database's password, where it is a server's: generated into a
        // file outside the project, named in .env, never written in it.
        $passwordFile = null;
        if ($answers->database->isServer()) {
            $passwordFile = StateFile::of($directory, 'database-password')
                ?? throw new \RuntimeException('No state directory to keep the database password in, outside the project: set HOME or XDG_STATE_HOME.');
            SecretFiles::ensure('APP_DATABASE_PASSWORD', $passwordFile, []);
        }
        AppFiles::create("{$directory}/.env", DevEnvironment::contents($answers, $passwordFile) . "\n");
        chmod("{$directory}/.env", 0o600);
        TenancySetup::apply($directory, $answers->tenancy);
        ($this->out)("wrote the app, its .env and its settings\n");

        if (!$answers->install) {
            ($this->out)($this->nextSteps($answers, $directory, false, $passwordFile));

            return 0;
        }

        $env = $this->childEnvironment();
        $php = PHP_BINARY;
        // Resolved first with nothing downloaded or run, then installed.
        $this->must($processes->run(['composer', 'update', '--no-install', '--no-plugins', '--no-scripts', '--no-progress', '--no-audit', '--no-interaction'], $directory, $env), 'composer could not resolve the app\'s packages; see what it said above.');
        $this->must($processes->run(['composer', 'install', '--no-progress', '--no-interaction'], $directory, $env), 'composer install failed; see what it said above.');
        if ($processes->has('npm')) {
            $source->prepareNpm($directory, $processes);
        }
        foreach ($answers->kits as $package) {
            $this->must($processes->run([$php, 'bin/tinvar', 'kit:install', $package, '--no-interaction'], $directory, $env), "The {$package} kit did not install; see what it said above.");
        }

        // The key and the probe token, codegen, the migrations and npm ci:
        // the skeleton's own setup, as `composer create-project` runs it.
        $ready = $processes->run([$php, 'bin/tinvar', 'setup', '--no-next-steps', '--no-interaction'], $directory, $env) === 0;
        $seeded = $ready && $processes->run([$php, 'bin/tinvar', 'db:seed', '--non-production', '--no-interaction'], $directory, $env) === 0;
        if ($answers->deploy !== null) {
            $this->must($processes->run([$php, 'bin/tinvar', 'deploy:init', $answers->deploy->value, '--no-interaction'], $directory, $env), 'deploy:init failed; see what it said above.');
        }

        ($this->out)($this->nextSteps($answers, $directory, $ready && $seeded, $passwordFile));

        return $ready || $answers->database->isServer() ? 0 : 1;
    }

    /**
     * The answers: each flag given, each question asked where a person is
     * there, each default where not.
     *
     */
    private function answers(NewAppFlags $flags, SkeletonSource $source, Processes $processes): NewAppAnswers
    {
        $ask = Prompts::canAsk();
        $directory = $flags->directory;
        $directory ??= $ask ? Prompts::text('Where should the app go?', placeholder: 'my-app', required: 'Name a directory for the app.') : null;
        if ($directory === null || $directory === '') {
            throw new \InvalidArgumentException('Name the directory to make the app in.');
        }
        $cwd = $this->cwd !== '' ? $this->cwd : (string) getcwd();
        $absolute = str_starts_with($directory, '/') ? $directory : "{$cwd}/{$directory}";
        if (is_dir($absolute) && array_diff(scandir($absolute) ?: [], ['.', '..']) !== []) {
            throw new \InvalidArgumentException("{$absolute} is not empty: tinvar new makes an app only in a new or empty directory.");
        }
        if (file_exists($absolute) && !is_dir($absolute)) {
            throw new \InvalidArgumentException("{$absolute} is a file.");
        }
        if (preg_match('/^[a-z][a-z0-9-]*\z/', basename($absolute)) !== 1) {
            throw new \InvalidArgumentException("'" . basename($absolute) . "' is not a name the app can take: lowercase letters, digits and dashes, starting with a letter, as its key file and its platforms name it.");
        }

        $database = self::choice($flags, 'database', DatabaseDriver::class, $ask, 'Which database?', [
            DatabaseDriver::Sqlite->value => 'SQLite: a file beside the app, nothing to run',
            DatabaseDriver::Postgres->value => 'PostgreSQL',
            DatabaseDriver::Mysql->value => 'MySQL',
        ], DatabaseDriver::Sqlite);
        $identifier = trim((string) preg_replace('/[^a-z0-9]+/', '_', basename($absolute)), '_');
        $host = $flags->option('database-host') ?? '127.0.0.1';
        $name = $flags->option('database-name') ?? $identifier;
        $user = $flags->option('database-user') ?? $identifier;
        if ($database->isServer() && $ask && $flags->option('database-host') === null) {
            $host = Prompts::text('Its host?', default: $host);
            $name = Prompts::text('The database\'s name?', default: $name);
            $user = Prompts::text('The user the app connects as?', default: $user);
        }
        $port = $flags->option('database-port') === null ? null : (int) $flags->option('database-port');
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('--database-port is a port, 1 to 65535.');
        }

        $tenancy = self::choice($flags, 'tenancy', TenantLookup::class, $ask, 'Is the app multi-tenant, and how is a tenant found?', TenantLookup::options(), TenantLookup::Off);
        $mailOptions = ['log' => 'Not yet: write mail to a log on this machine'];
        foreach (MailProvider::cases() as $provider) {
            if (!\in_array($provider, [MailProvider::Log, MailProvider::Array], true)) {
                $mailOptions[$provider->value] = $provider->value;
            }
        }
        $mail = self::choice($flags, 'mail', MailProvider::class, $ask, 'Which mail provider? (TINVAR_MAIL_PROVIDER)', $mailOptions, MailProvider::Log);
        if ($mail === MailProvider::Array) {
            throw new \InvalidArgumentException('--mail=array keeps mail in memory, for tests: pick log or a provider.');
        }

        $kits = $this->kits($flags, $ask, $source, $processes);

        $deployOptions = ['none' => 'Not now', ...DeployTarget::options()];
        $deploy = $flags->option('deploy') ?? ($ask ? (string) Prompts::select('Where will it deploy?', $deployOptions, default: 'none') : 'none');
        $target = $deploy === 'none' ? null : (DeployTarget::tryFrom($deploy) ?? throw new \InvalidArgumentException("--deploy is none, fly, render, railway, vps-systemd or vps-compose, not '{$deploy}'."));

        return new NewAppAnswers(
            directory: $absolute,
            database: $database,
            databaseHost: $host,
            databasePort: $port,
            databaseName: $name,
            databaseUser: $user,
            tenancy: $tenancy,
            mail: $mail,
            kits: $kits,
            deploy: $target,
            install: !$flags->has('no-install'),
        );
    }

    /**
     * The kits to install: accounts where --accounts says or a person says
     * yes -- asked only where the source has an accounts kit -- and each
     * --kit=PACKAGE, each one the source must have.
     *
     * @return list<string>
     */
    private function kits(NewAppFlags $flags, bool $ask, SkeletonSource $source, Processes $processes): array
    {
        $wantsAccounts = $flags->has('accounts') ? true : ($flags->has('no-accounts') ? false : null);
        $named = $flags->kits;
        $available = ($wantsAccounts === true || $named !== [] || ($ask && $wantsAccounts === null)) ? $source->kits($processes) : [];
        $byPackage = [];
        foreach ($available as $declaration) {
            $byPackage[$declaration->package] = $declaration;
        }

        $kits = [];
        $accounts = $available['accounts'] ?? null;
        if ($wantsAccounts === true && !$accounts instanceof KitDeclaration) {
            throw new \InvalidArgumentException("--accounts, and {$source->describe()} has no accounts kit yet: make the app without it, and add it later with `composer require tinvar/accounts` and `bin/tinvar kit:install tinvar/accounts`.");
        }
        if ($accounts instanceof KitDeclaration && ($wantsAccounts ?? ($ask && Prompts::confirm("Include accounts? ({$accounts->title})", default: false)))) {
            $kits[] = $accounts->package;
        }
        foreach ($named as $package) {
            if (!isset($byPackage[$package])) {
                throw new \InvalidArgumentException("--kit={$package}: {$source->describe()} has no kit by that package.");
            }
            $kits[] = $package;
        }

        return array_values(array_unique($kits));
    }

    /**
     * A flag's enum case, the answer to $question where a person is there,
     * or $default.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @param array<string, string> $options
     * @param T $default
     * @return T
     */
    private static function choice(NewAppFlags $flags, string $flag, string $enum, bool $ask, string $question, array $options, \BackedEnum $default): \BackedEnum
    {
        $given = $flags->option($flag) ?? ($ask ? (string) Prompts::select($question, $options, default: (string) $default->value) : (string) $default->value);
        $case = isset($options[$given]) ? $enum::tryFrom($given) : null;

        return $case ?? throw new \InvalidArgumentException("--{$flag} is " . implode(', ', array_keys($options)) . ", not '{$given}'.");
    }

    /** @return array<string, string> this process's environment, without another app's settings */
    private function childEnvironment(): array
    {
        return array_diff_key(array_filter(getenv(), \is_string(...)), array_flip(self::NOT_INHERITED)) + ['COMPOSER_NO_INTERACTION' => '1'];
    }

    private function must(int $status, string $failure): void
    {
        if ($status !== 0) {
            throw new \RuntimeException($failure);
        }
    }

    /** What to do next, as the setup's own last lines say it, and what this run left to do. */
    private function nextSteps(NewAppAnswers $answers, string $directory, bool $ready, ?string $passwordFile): string
    {
        $cd = escapeshellarg($directory);
        $lines = ['', "{$answers->name()} is made. Next:", '', "  cd {$cd}"];
        if (!$answers->install) {
            $lines[] = '  composer update && composer install    # then bin/tinvar setup';
        }
        if ($answers->database->isServer() && !$ready) {
            $database = $answers->databaseName !== '' ? $answers->databaseName : $answers->identifier();
            $user = $answers->databaseUser !== '' ? $answers->databaseUser : $answers->identifier();
            $lines[] = $answers->database === DatabaseDriver::Postgres
                ? "  createuser --pwprompt {$user} && createdb --owner={$user} {$database}"
                : "  mysql -e \"CREATE DATABASE {$database}; CREATE USER '{$user}'@'localhost' IDENTIFIED BY '...'; GRANT ALL ON {$database}.* TO '{$user}'@'localhost'\"";
            if ($passwordFile !== null) {
                $lines[] = "  #   the password is in {$passwordFile}: pbcopy < " . escapeshellarg($passwordFile) . ' and paste it';
            }
            $lines[] = '  bin/tinvar migrate && bin/tinvar db:seed --non-production';
        }
        if ($answers->install && !is_dir("{$directory}/node_modules")) {
            $lines[] = '  npm ci --ignore-scripts             # setup said above why it did not; Node 22.13 or later';
        }
        $lines[] = '  bin/tinvar doctor                   # every check the app needs, each problem with its fix';
        $lines[] = '  bin/dev                             # dev mode: renders from source, reloads as you edit';
        $lines[] = '  npm run build && bin/serve          # or the build, as production serves it';
        $lines[] = '  vendor/bin/pest && npx vitest run   # the tests';
        if ($answers->tenancy !== TenantLookup::Off) {
            $lines[] = '';
            $lines[] = '  Tenancy is registered: mark a model #[TenantScoped] and an entry point';
            $lines[] = '  #[TenantFrom(Host::class)], and add a row to tenants for each tenant'
                . ($answers->tenancy === TenantLookup::Subdomain ? ' (http://<slug>.localhost:PORT on this machine).' : ' (its host on the row).');
        }
        if ($answers->deploy !== null) {
            $lines[] = '';
            $lines[] = "  The {$answers->deploy->title()} deployment is written: its steps are above.";
        }

        return implode("\n", $lines) . "\n";
    }
}
