<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Auth\BuildRules;
use Tinvar\Config\Configs;
use Tinvar\Gateway\GatewayKeys;
use Tinvar\Generators\AppLayout;
use Tinvar\Generators\GenerationRefused;
use Tinvar\Generators\MakeCommand;
use Tinvar\Generators\Registrations;
use Tinvar\Http\BuildStore;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\DatabaseSeeder;
use Tinvar\Orm\Migrations\MigrateCommand;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\PdoConnection;
use Tinvar\Packages\Lockfiles;
use Tinvar\Packages\PackageLock;
use Tinvar\Packages\PackagePolicy;
use Tinvar\Packages\SupplyChain;
use Tinvar\Render\FragmentManifestProducer;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Server\BuildCommand;
use Tinvar\Server\CaddyfileRules;
use Tinvar\Server\ComposerSources;
use Tinvar\Server\ConfigCommand;
use Tinvar\Server\DevCommand;
use Tinvar\Server\PhpCommand;
use Tinvar\Server\ProfileBuildCommand;
use Tinvar\Server\RendererCommand;
use Tinvar\Server\ServeCommand;

/**
 * An application's command line: the framework's commands, pointed at one
 * application -- what every `bin/tinvar` runs, the skeleton's and this
 * workspace's alike.
 *
 *     exit(new AppConsole(
 *         root: __DIR__ . '/..',
 *         console: __FILE__,
 *         namespace: 'App',
 *         routes: Kernel::ROUTES, queries: Kernel::QUERIES, actions: Kernel::ACTIONS,
 *         models: Kernel::MODELS, configs: Kernel::CONFIGS,
 *         packages: Kernel::packages(...), buildStore: Kernel::buildStore(...),
 *         database: Kernel::databasePath(...), migrations: Migrations::PATH,
 *     )->run($argv));
 *
 * It owns the commands every application has, each the framework's own
 * class handed the application's declarations: check, config, codegen,
 * serve, dev, build and the build history, build:fragments, the renderer
 * pool, the migrations, the generators (which register what they write:
 * the Kernel's lists and the route table, ADR 0110), packages:lock and
 * audit. An application adds its own with command() -- a command of the
 * same name takes the built-in's place -- and its own checks with
 * withCheck().
 *
 * Codegen is tinvar/codegen's, a dev dependency the framework does not
 * name: the application hands its command in, and where a production
 * install left it out, `codegen` says so.
 */
final class AppConsole
{
    /** @var array<string, array{usage: string, about: string, run: \Closure(list<string>): int}> */
    private array $commands = [];

    /** @var list<array{string, \Closure(): list<string>, string}> heading, the check, its line when it passes */
    private array $checks = [];

    private readonly string $root;

    private readonly string $projectRoot;

    /**
     * @param string $root the application: its Caddyfile, resources/ and dist/
     * @param string $console the script this runs as, which dev and serve run again
     * @param string $namespace the application's root namespace, which the generators write under
     * @param list<class-string> $routes the pages and layouts
     * @param list<class-string> $queries
     * @param list<class-string> $actions
     * @param list<class-string<\Tinvar\Orm\Model>> $models
     * @param list<class-string> $configs the #[Config] classes
     * @param \Closure(): PackagePolicy $packages
     * @param \Closure(): BuildStore $buildStore
     * @param \Closure(): string $database the SQLite database the migrations run against
     * @param string $migrations the migrations' directory
     * @param string|null $projectRoot where composer.json and the lockfiles are; the application's root by default
     * @param list<class-string> $payloads Data classes nothing routable reaches
     * @param list<class-string> $entryPoints entry points beside the routes, queries and actions:
     *        endpoints, channels, transfers, held to the build rules
     * @param (\Closure(list<string>): int)|null $codegen tinvar/codegen's command, where it is installed
     * @param Registrations|null $registrations where the generators register what they write; by
     *        default the lists of $kernel's file and the route table in resources/entries/rsc.tsx
     * @param class-string|null $kernel the application's Kernel, whose ROUTES, QUERIES, ACTIONS and
     *        MODELS the generators add to
     * @param (\Closure(): array<string, string>)|null $serverEnvironment what serve hands FrankenPHP alone
     * @param bool $workers whether serve may run this console's queue:work and schedule:work beside it
     * @param DatabaseSeeder|null $seeder db:seed's, where the application seeds
     * @param string $connection the name the migrations' connection is opened under
     * @param (\Closure(): ?\Tinvar\Observability\RendererLines)|null $rendererLog the application's log,
     *        which serve writes its renderers' lines to as well as to standard error (ADR 0128)
     */
    public function __construct(
        string $root,
        private readonly string $console,
        private readonly string $namespace,
        private readonly array $routes,
        private readonly array $queries,
        private readonly array $actions,
        private readonly array $models,
        private readonly array $configs,
        private readonly \Closure $packages,
        private readonly \Closure $buildStore,
        private readonly \Closure $database,
        private readonly string $migrations,
        ?string $projectRoot = null,
        private readonly array $payloads = [],
        private readonly array $entryPoints = [],
        private readonly ?\Closure $codegen = null,
        private ?Registrations $registrations = null,
        private readonly ?string $kernel = null,
        private readonly ?\Closure $serverEnvironment = null,
        private readonly bool $workers = false,
        private readonly ?DatabaseSeeder $seeder = null,
        private readonly string $connection = 'app',
        private readonly string $name = 'bin/tinvar',
        private readonly ?\Closure $rendererLog = null,
    ) {
        $this->root = realpath($root) ?: $root;
        $this->projectRoot = $projectRoot === null ? $this->root : (realpath($projectRoot) ?: $projectRoot);
        $this->builtIns();
    }

    /**
     * Adds a command, or puts one in a built-in's place.
     *
     * @param \Closure(list<string>): int $run handed what follows the command's name
     */
    public function command(string $name, string $usage, string $about, \Closure $run): self
    {
        $this->commands[$name] = ['usage' => $usage, 'about' => $about, 'run' => $run];

        return $this;
    }

    /**
     * A check `check` runs beside the built-in ones.
     *
     * @param \Closure(): list<string> $check each violation, none when it passes
     */
    public function withCheck(string $heading, \Closure $check, string $passed): self
    {
        $this->checks[] = [$heading, $check, $passed];

        return $this;
    }

    /**
     * `gateway:keys` (ADR 0119): the public keys node agents and the console
     * edge verify PHP's commands and tickets with, for this environment's
     * profile, from the key $appKey gives -- the application's, bound to
     * the profile (AppKey::application()). The public halves alone.
     *
     * @param \Closure(): string $appKey
     */
    public function withGatewayKeys(\Closure $appKey): self
    {
        return $this->command('gateway:keys', 'gateway:keys', "Print the public keys this environment's node agents and console edge verify PHP's commands and tickets with; never a secret", static function (array $arguments) use ($appKey): int {
            if ($arguments !== []) {
                fwrite(STDERR, "Usage: gateway:keys\n");

                return 64;
            }
            echo implode("\n", GatewayKeys::lines($appKey())), "\n";

            return 0;
        });
    }

    /** @param list<string> $argv the script's own, as PHP hands it over */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $arguments = \array_slice($argv, 2);

        if (\in_array($command, ['help', '--help', '-h'], true)) {
            return $this->help();
        }
        $known = $this->commands[$command] ?? null;

        return $known === null ? $this->help("Unknown command '{$command}'.") : ($known['run'])($arguments);
    }

    /** Whether $name is a command here. */
    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function help(string $error = ''): int
    {
        if ($error !== '') {
            fwrite(STDERR, $error . "\n\n");
        }

        $lines = ["{$this->name} <command>", ''];
        foreach ($this->commands as ['usage' => $usage, 'about' => $about]) {
            if ($about === '') {
                continue;
            }
            $lines[] = \strlen($usage) <= 21 ? \sprintf('  %-22s %s', $usage, self::indent($about, false)) : "  {$usage}\n" . self::indent($about, true);
        }
        $lines[] = \sprintf('  %-22s %s', 'help', 'This message');
        echo implode("\n", $lines) . "\n";

        return $error === '' ? 0 : 64;
    }

    /**
     * The build rules over every entry point and payload, the config
     * classes, the supply chain and the Caddyfile, and the application's
     * own checks; every failure named, under its heading.
     */
    public function check(): int
    {
        $entryPoints = [...$this->routes, ...$this->queries, ...$this->actions, ...$this->entryPoints];
        $payloads = array_values(array_unique([...$this->payloads, ...BuildRules::payloadsTakenBy($entryPoints)]));
        $results = [
            // The pages and layouts, registered as the Kernel registers
            // them; the build rules over every entry point and every Data
            // object and form request one takes; and the config classes,
            // held as a boot holds them, with no variable read twice.
            ['Build rules failed:', [
                // Given the models, as the Kernel's registry is, so it
                // refuses a bare id to a tenant's model too (ADR 0105).
                ...RouteRegistry::check($this->routes, new RouteRegistry($this->models)),
                ...new BuildRules($this->models)->check($entryPoints, $payloads),
                ...Configs::check($this->configs),
            ], [
                \sprintf('ok: %d entry points, %d payloads, no violations', \count($entryPoints), \count($payloads)),
                \sprintf('ok: %d config classes, every secret a Secret', \count($this->configs)),
            ]],
            ['Supply chain failed:', new SupplyChain($this->projectRoot, ($this->packages)())->check(), [\sprintf('ok: %s and %s pinned, no undeclared packages', Lockfiles::COMPOSER, Lockfiles::NPM)]],
            // Read through its imports: every PHP response held whole, the
            // X-Accel headers kept from clients, and a stalled write cut off
            // by `write_idle` (ADR 0081, 0105).
            ['Caddyfile failed:', CaddyfileRules::check("{$this->root}/Caddyfile"), ['ok: Caddyfile holds every PHP response whole, keeps the X-Accel headers from clients and cuts off a stalled write']],
        ];
        foreach ($this->checks as [$heading, $check, $passed]) {
            $results[] = [$heading, $check(), [$passed]];
        }

        $failed = false;
        foreach ($results as [$heading, $violations]) {
            if ($violations !== []) {
                $failed = true;
                fwrite(STDERR, $heading . "\n  " . implode("\n  ", $violations) . "\n");
            }
        }
        if ($failed) {
            return 1;
        }
        foreach ($results as [, , $passed]) {
            echo implode("\n", $passed) . "\n";
        }

        return 0;
    }

    /**
     * The renderers and FrankenPHP, together; see ServeCommand. This
     * application and no other, unless the console serves any it is given.
     *
     * @param list<string> $arguments
     */
    public function serve(array $arguments): int
    {
        $app = null;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--app=')) {
                $app = realpath(substr($argument, 6)) ?: substr($argument, 6);
            }
            if (!$this->workers && ($argument === '--worker' || $argument === '--schedule')) {
                fwrite(STDERR, "This app has no queue or schedule for {$argument} to run.\n");

                return 64;
            }
        }
        if ($this->projectRoot === $this->root) {
            if ($app === null) {
                $arguments[] = "--app={$this->root}";
            } elseif ($app !== $this->root) {
                fwrite(STDERR, "{$this->name} serves the app it is in, {$this->root}, and no other.\n");

                return 64;
            }
        }

        return new ServeCommand(
            $this->projectRoot,
            serverEnvironment: $this->serverEnvironment,
            console: $this->workers ? $this->console : null,
            rendererLog: $this->rendererLog === null ? null : ($this->rendererLog)(),
        )->run($arguments);
    }

    /**
     * The migrations, against the application's database or --database=PATH.
     * migrate, migrate:fresh and db:seed make the file when it is missing,
     * since migrating is how a database comes to be; the rest refuse a
     * missing one rather than make an empty database at a mistyped path.
     *
     * @param list<string> $arguments
     */
    public function migrate(string $command, array $arguments): int
    {
        $database = ($this->database)();
        $rest = [];
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--database=')) {
                $database = substr($argument, 11);
            } else {
                $rest[] = $argument;
            }
        }
        $connection = $this->connection;
        $script = $this->name;

        return new MigrateCommand(
            static fn (bool $create): Connection => $create || is_file($database)
                ? PdoConnection::sqlite($database, $connection, create: $create)
                : throw new MigrationRefused("No database at {$database}; `{$script} migrate` makes one."),
            $this->migrations,
            $this->models,
            seeder: $this->seeder,
        )->run($command, $rest);
    }

    /**
     * Where the generators register what they write: as given, or the
     * Kernel's own lists and the route table where each is there.
     */
    public function registrations(): Registrations
    {
        if ($this->registrations !== null) {
            return $this->registrations;
        }
        $lists = [];
        $file = $this->kernel === null || !class_exists($this->kernel) ? false : new \ReflectionClass($this->kernel)->getFileName();
        if (\is_string($file)) {
            foreach (['page' => 'ROUTES', 'query' => 'QUERIES', 'action' => 'ACTIONS', 'model' => 'MODELS'] as $kind => $constant) {
                if (\defined("{$this->kernel}::{$constant}")) {
                    $lists[$kind] = [['file' => $file, 'constant' => $constant]];
                }
            }
        }
        $table = "{$this->root}/resources/entries/rsc.tsx";

        return $this->registrations = new Registrations($lists, is_file($table) ? $table : null, $this->routes);
    }

    private function builtIns(): void
    {
        $this->command('check', 'check', "Run the security model's build rules over the app, hold its lockfiles to its PackagePolicy (no network), and its Caddyfile to holding each response whole and bounding a stalled write", fn (array $a): int => $a === [] ? $this->check() : $this->usage('check'))
            ->command('config', 'config', "Every key the app's #[Config] classes read, where each value comes from, and no secret's; exits 1, naming each key, where the server would refuse to boot", fn (array $a): int => new ConfigCommand($this->configs)->run($a))
            ->command('codegen', 'codegen [--out=DIR] [--check]', 'Write the TypeScript the views use from the PHP; with --check, write nothing and fail if what is there differs', $this->codegen ?? static function (): int {
                fwrite(STDERR, "tinvar/codegen is not installed: it is a dev dependency, which this install left out.\n");

                return 1;
            })
            ->command('serve', 'serve [--host=HOST] [--port=PORT] [--workers=N] [--renderers=N] [--frankenphp=PATH]', 'Serve the build with FrankenPHP workers and a pool of renderers', $this->serve(...))
            ->command('dev', 'dev [--host=HOST] [--port=PORT] [--frankenphp=PATH]', "Vite's dev server as the renderer, codegen on every PHP change, and the dev toolbar, on loopback; never in production", fn (array $a): int => new DevCommand(
                console: $this->console,
                // Where composer.json autoloads the application from; app/
                // where it names nothing under the application.
                watch: ComposerSources::of($this->projectRoot, $this->root) ?: (is_dir("{$this->root}/app") ? ["{$this->root}/app"] : []),
                codegen: fn (string $app): array => [...PhpCommand::prefix(), $this->console, 'codegen', "--out={$app}/resources/types"],
                // An app with a console of its own runs where it is; one
                // console over a workspace's apps, where it was run from.
                workingDirectory: $this->projectRoot === $this->root ? $this->root : null,
            )->run($a))
            ->command('build', 'build [--profile=production|staging|test] [--app=DIR] [--out=DIR]', 'The Vite build, made for a profile: production unless --profile says; each is served only under its own TINVAR_PROFILE', fn (array $a): int => new ProfileBuildCommand($this->projectRoot === $this->root ? $this->root : (string) getcwd())->run($a))
            ->command('build:fragments', 'build:fragments [--build=DIR]', "Write the fragment manifest -- which routes may defer, and what each route's renders may query -- from the build and the routes", $this->fragments(...));
        foreach (['build:current' => 'build:current [<id>] | build:revoke <id> | build:floor <id> | build:list | build:prune [--keep=N]', 'build:revoke' => '', 'build:floor' => '', 'build:list' => '', 'build:prune' => ''] as $name => $usage) {
            $this->command($name, $usage, $usage === '' ? '' : 'The build history: what is released, and what is refused', fn (array $a): int => new BuildCommand(($this->buildStore)())->run($name, $a));
        }
        foreach (['renderer:start' => 'renderer:start | renderer:stop | renderer:check | renderer:restart', 'renderer:stop' => '', 'renderer:check' => '', 'renderer:restart' => ''] as $name => $usage) {
            $this->command($name, $usage, $usage === '' ? '' : 'The renderer pool, by hand', fn (array $a): int => new RendererCommand($this->projectRoot)->run($name, $a));
        }
        foreach ([
            'migrate' => ['migrate | migrate:rollback [--step=N] | migrate:status | migrate:fresh --force', 'The migrations, against the app\'s database (or --database=PATH)'],
            'migrate:rollback' => ['', ''],
            'migrate:status' => ['', ''],
            'migrate:fresh' => ['', ''],
            'make:migration' => ['make:migration <name> [--blank] [--rename=TABLE.OLD:NEW]', 'Write the migration that brings the database to the models; a rename only where --rename or a terminal says one'],
            'db:mark-production' => ['db:mark-production', "Mark the database production's, as migrate does wherever the environment says production: migrate:fresh and db:seed are then refused on it whatever the environment says (ADR 0103)"],
        ] as $name => [$usage, $about]) {
            $this->command($name, $usage, $about, fn (array $a): int => $this->migrate($name, $a));
        }
        if ($this->seeder !== null) {
            $this->command('db:seed', 'db:seed [--fresh --force] [--non-production]', 'Migrate, then seed; not in production, as for migrate:fresh', fn (array $a): int => $this->migrate('db:seed', $a));
        }
        foreach (MakeCommand::COMMANDS as $i => $name) {
            $this->command(
                $name,
                $i === 0 ? "make:page <Name> --path=/racks/{rack} [--view=racks/show]\n  make:query <Name> [--model=Rack]\n  make:action <Class> <method> [--model=Rack] [--request]\n  make:model <Name> [--table=racks] [--columns=name:string,heightU:int,notes:?string] [--factory]" : '',
                $i === 0 ? "Write a page, query, action or model, and what each needs, and register it in the app's lists and route table where each reads as one; every check they write fails closed until you decide otherwise" : '',
                fn (array $a): int => $this->generate($name, $a),
            );
        }
        $this->command('packages:lock', 'packages:lock', "Pin each enabled package's version and files in tinvar.lock", fn (array $a): int => $this->packagesLock())
            ->command('audit', 'audit', 'composer audit and npm audit over the lockfiles (network)', fn (array $a): int => $this->audit());
    }

    /** @param list<string> $arguments */
    private function generate(string $command, array $arguments): int
    {
        try {
            $layout = AppLayout::fromComposer($this->projectRoot, $this->namespace, pages: "{$this->root}/resources/pages", types: "{$this->root}/resources/types");
        } catch (GenerationRefused $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return MakeCommand::FAILED;
        }

        return new MakeCommand($layout, $this->models, registrations: $this->registrations())->run($command, $arguments);
    }

    /**
     * The fragment manifest, from the build's deferred.json and the routes;
     * part of the build, after Vite.
     *
     * @param list<string> $arguments
     */
    private function fragments(array $arguments): int
    {
        $build = "{$this->root}/dist";
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--build=')) {
                return $this->usage('build:fragments');
            }
            $build = rtrim(substr($argument, 8), '/');
        }

        $routes = new RouteRegistry();
        $routes->registerAll($this->routes);
        try {
            $manifest = FragmentManifestProducer::produce($routes, "{$build}/rsc/deferred.json");
            $manifest->write("{$build}/fragments.json");
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
        foreach ($manifest->toArray()['queries'] as $route => $queries) {
            printf("queries %s (%s)\n", $route, $queries === [] ? 'none' : implode(', ', $queries));
        }
        printf("wrote %s\n", "{$build}/fragments.json");

        return 0;
    }

    /** tinvar.lock, written from what is installed for each package the policy enables. */
    private function packagesLock(): int
    {
        try {
            $lockfiles = new Lockfiles($this->projectRoot);
            $lock = PackageLock::of(($this->packages)(), [...$lockfiles->composer(), ...$lockfiles->npm()]);
            $lock->write($this->projectRoot);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
        printf("Wrote %s: %d package(s) pinned.\n", PackageLock::FILE, \count($lock->entries));

        return 0;
    }

    /** The advisory databases' view of both lockfiles. Needs the network. */
    private function audit(): int
    {
        $status = 0;
        foreach ([['composer', 'audit', '--locked', '--no-interaction'], ['npm', 'audit', '--audit-level=moderate']] as $command) {
            echo '$ ' . implode(' ', $command) . "\n";
            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $this->projectRoot);
            $code = \is_resource($process) ? proc_close($process) : 127;
            $status = $status === 0 ? $code : $status;
        }

        return $status === 0 ? 0 : 1;
    }

    private function usage(string $command): int
    {
        fwrite(STDERR, "Usage: {$this->name} {$this->commands[$command]['usage']}\n");

        return 64;
    }

    /** $about wrapped to the help's column, its first line inline unless $first says otherwise. */
    private static function indent(string $about, bool $first): string
    {
        $lines = explode("\n", wordwrap($about, 70));

        return ($first ? str_repeat(' ', 25) : '') . implode("\n" . str_repeat(' ', 25), $lines);
    }
}
