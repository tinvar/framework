<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Auth\ApplicationClasses;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\Violation;
use Tinvar\Channels\ChannelRegistry;
use Tinvar\Config\Configs;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Console\Deploy\DeployInitCommand;
use Tinvar\Console\Doctor\Doctor;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Kits\KitInstaller;
use Tinvar\Console\Kits\KitTarget;
use Tinvar\Console\NewApp\NewAppCommand;
use Tinvar\Http\EntryContext;
use Tinvar\Console\Prompts\Cancelled;
use Tinvar\Console\Prompts\CannotPrompt;
use Tinvar\Console\Prompts\Progress;
use Tinvar\Console\Secrets\SecretFiles;
use Tinvar\Discovery\DiscoveredClasses;
use Tinvar\Discovery\Discovery;
use Tinvar\Discovery\DiscoveryFailed;
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
use Tinvar\Packages\Advisories;
use Tinvar\Packages\Lockfiles;
use Tinvar\Packages\PackageLock;
use Tinvar\Packages\PackagePolicy;
use Tinvar\Packages\SupplyChain;
use Tinvar\Render\FragmentManifestProducer;
use Tinvar\Routing\Domains;
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
use Tinvar\Support\BuildProfile;
use Tinvar\Transfers\TransferRegistry;

/**
 * An application's command line: the framework's commands, pointed at one
 * application -- what every `bin/tinvar` runs, the skeleton's and this
 * workspace's alike.
 *
 *     exit(new AppConsole(
 *         root: __DIR__ . '/..',
 *         console: __FILE__,
 *         namespace: 'App',
 *         classes: Kernel::classes(...),
 *         packages: Kernel::packages(...), buildStore: Kernel::buildStore(...),
 *         database: Kernel::databasePath(...), migrations: Migrations::PATH,
 *         kernel: Kernel::class,
 *     )->run($argv));
 *
 * It owns the commands every application has, each the framework's own
 * class handed the application's declarations -- the classes its Kernel
 * registers, discovered under its namespace (ADR 0146): check, config,
 * codegen, serve, dev, build and the build history, build:fragments,
 * discover, the renderer pool, the migrations, the generators (which write
 * classes discovery finds, and add a page's view to the route table, ADR
 * 0110), packages:lock and audit. An application adds its own with
 * command() -- a command of the same name takes the built-in's place --
 * and its own checks with withCheck().
 *
 * A scan discovery refuses stops every command that needs the classes,
 * saying each problem, and `check` names them under their own heading.
 *
 * Codegen is tinvar/codegen's, a dev dependency the framework does not
 * name: the application hands its command in, and where a production
 * install left it out, `codegen` says so.
 *
 * Commands ask what they were not given, and confirm what destroys, where
 * a person is there (Prompts, ADR 0144). `--no-interaction` or `-n`,
 * anywhere on the line, says nobody is: read here, for every command, which
 * then never sees it. A prompt cancelled with ctrl-c exits 130; one nobody
 * was there to answer, 64, saying what to pass instead.
 */
final class AppConsole
{
    /** The rule `tinvar check` reports a host no Domains serves under (ADR 0149). */
    public const string HOSTS_MUST_BE_SERVED = 'hosts-must-be-served';

    /** The rule `tinvar check` reports a route on a `{tenant}` host reading its tenant from elsewhere under. */
    public const string TENANT_HOST_READS_HOST = 'tenant-host-reads-host';

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
     * @param \Closure(): DiscoveredClasses $classes what the application registers -- its Kernel's
     *        classes(): discovered, with what it adds by hand -- asked when a command needs them
     * @param \Closure(): PackagePolicy $packages
     * @param \Closure(): BuildStore $buildStore
     * @param \Closure(): string $database the SQLite database the migrations run against
     * @param string $migrations the migrations' directory
     * @param string|null $projectRoot where composer.json and the lockfiles are; the application's root by default
     * @param (\Closure(list<string>): int)|null $codegen tinvar/codegen's command, where it is installed
     * @param Registrations|null $registrations where a page's view is registered; by default the
     *        route table in resources/entries/rsc.tsx
     * @param class-string|null $kernel the application's Kernel: discovery reads the classes under its
     *        namespace, and `discover`, `build` and `check` its manifest in the build (ADR 0146)
     * @param (\Closure(): array<string, string>)|null $serverEnvironment what serve hands FrankenPHP alone
     * @param bool $workers whether serve may run this console's queue:work and schedule:work beside it
     * @param DatabaseSeeder|null $seeder db:seed's, where the application seeds
     * @param string $connection the name the migrations' connection is opened under
     * @param (\Closure(): ?\Tinvar\Observability\RendererLines)|null $rendererLog the application's log,
     *        which serve writes its renderers' lines to as well as to standard error (ADR 0128)
     * @param (\Closure(\Tinvar\Generators\ResourcePlan): string)|null $resourceTest the test
     *        make:resource writes, tinvar/testing's, where it is installed (ADR 0151)
     * @param (\Closure(bool): Connection)|null $connect the application's own database, told whether
     *        to make a SQLite file that is missing: what the migrations and `doctor` open where no
     *        --database=PATH names a SQLite file instead, so a Postgres or MySQL application migrates
     *        the server it runs on (ADR 0157). None, and $database's SQLite file
     * @param (\Closure(): ?string)|null $keyFile where the app key is generated when TINVAR_APP_KEY gives none,
     *        for `key:generate` and `doctor`; null where the application has no file
     * @param (\Closure(): ?string)|null $probeTokenFile where the probe token is, likewise
     * @param (\Closure(list<string>): int)|null $openapi tinvar/codegen's `openapi`, where it is
     *        installed (ADR 0155): the public API as an OpenAPI 3.1 document, which `build` also
     *        writes into the build as openapi.json when there is one to describe
     */
    public function __construct(
        string $root,
        private readonly string $console,
        private readonly string $namespace,
        private readonly \Closure $classes,
        private readonly \Closure $packages,
        private readonly \Closure $buildStore,
        private readonly \Closure $database,
        private readonly string $migrations,
        ?string $projectRoot = null,
        private readonly ?\Closure $codegen = null,
        private ?Registrations $registrations = null,
        private readonly ?string $kernel = null,
        private readonly ?\Closure $serverEnvironment = null,
        private readonly bool $workers = false,
        private readonly ?DatabaseSeeder $seeder = null,
        private readonly string $connection = 'app',
        private readonly string $name = 'bin/tinvar',
        private readonly ?\Closure $rendererLog = null,
        private readonly ?\Closure $resourceTest = null,
        private readonly ?\Closure $connect = null,
        private readonly ?\Closure $keyFile = null,
        private readonly ?\Closure $probeTokenFile = null,
        private readonly ?\Closure $openapi = null,
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
        $line = Prompts::readNoInteraction(\array_slice($argv, 1));
        $command = $line[0] ?? 'help';
        $arguments = \array_slice($line, 1);

        if (\in_array($command, ['help', '--help', '-h'], true)) {
            return $this->help();
        }
        $known = $this->commands[$command] ?? null;
        if ($known === null) {
            return $this->help("Unknown command '{$command}'.");
        }

        try {
            return ($known['run'])($arguments);
        } catch (Cancelled $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return Cancelled::STATUS;
        } catch (CannotPrompt $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 64;
        } catch (DiscoveryFailed $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * What the application registers: its Kernel's classes(), discovered
     * under its namespace with what it adds by hand.
     *
     * @throws DiscoveryFailed
     */
    public function classes(): DiscoveredClasses
    {
        return ($this->classes)();
    }

    /**
     * Discovery of the application's classes, and its manifest in $build
     * -- the build directory by default; none without a Kernel to read the
     * namespace of.
     */
    public function discovery(?string $build = null): ?Discovery
    {
        if ($this->kernel === null) {
            return null;
        }

        /** @var class-string $kernel */
        $kernel = $this->kernel;

        return Discovery::of($kernel, $build ?? BuildProfile::buildDirectory($this->root));
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
     * What EndpointRegistry::check() finds in the endpoints among
     * $entryPoints -- which hold channels and transfers too -- against the
     * pages and layouts in $routes.
     *
     * @param list<class-string> $entryPoints
     * @param list<class-string> $routes
     * @param list<class-string<\Tinvar\Orm\Model>> $models
     * @return list<Violation>
     */
    public static function endpointViolations(array $entryPoints, array $routes, array $models): array
    {
        $endpoints = array_values(array_filter($entryPoints, EndpointRegistry::holdsEndpoints(...)));
        if ($endpoints === []) {
            return [];
        }
        // A page the registry refuses is reported by the build rules; the
        // endpoints are held to the pages that do register.
        $registry = new RouteRegistry($models);
        RouteRegistry::check($routes, $registry);

        return EndpointRegistry::check($endpoints, $registry, $models);
    }

    /**
     * The hosts the application's Kernel configures, by its static
     * domains() where it has one (ADR 0149); null for none.
     */
    private function domains(): ?Domains
    {
        if ($this->kernel === null || !method_exists($this->kernel, 'domains')) {
            return null;
        }
        $domains = ($this->kernel)::domains();

        return $domains instanceof Domains ? $domains : null;
    }

    /**
     * What `tinvar check` reports of hosts: a page, layout or endpoint
     * declaring a #[Domain] the application configures no Domains for, one
     * naming a configured domain that does not exist, two patterns that
     * match one host, and a page, layout or endpoint on a `{tenant}` host
     * reading its tenant from anything but that host
     * (EntryContext::tenantHostRefusals()). A malformed pattern, and two routes of one host and
     * one path, are refused as each registers, under the build rules.
     *
     * @param list<class-string> $routes
     * @param list<class-string> $endpoints
     * @param list<class-string<\Tinvar\Orm\Model>> $models
     * @return list<Violation>
     */
    public static function hostViolations(array $routes, array $endpoints, array $models, ?Domains $domains): array
    {
        $registry = new RouteRegistry($models);
        RouteRegistry::check($routes, $registry);
        $patterns = array_map(static fn ($p): string => $p->pattern, $registry->hostPatterns());
        $holders = array_values(array_filter($endpoints, EndpointRegistry::holdsEndpoints(...)));
        $endpointRegistry = null;
        if ($holders !== []) {
            $endpointRegistry = new EndpointRegistry($models);
            foreach ($holders as $class) {
                try {
                    $endpointRegistry->register($class);
                } catch (\RuntimeException|\LogicException) {
                    // Reported by the endpoints' own check.
                }
            }
            $patterns = [...$patterns, ...array_map(static fn ($p): string => $p->pattern, $endpointRegistry->hostPatterns())];
        }
        $patterns = array_values(array_unique($patterns));

        $tenantHosts = array_map(
            static fn (string $refusal): Violation => new Violation(self::TENANT_HOST_READS_HOST, substr($refusal, 0, (int) strpos($refusal, ' is on ')), substr($refusal, (int) strpos($refusal, ' is on ') + 1)),
            EntryContext::tenantHostRefusals($registry, $endpointRegistry),
        );
        if ($tenantHosts !== []) {
            return $tenantHosts;
        }
        if ($patterns === []) {
            return [];
        }
        if ($domains === null) {
            return [new Violation(self::HOSTS_MUST_BE_SERVED, $patterns[0], 'is a host a route declares, and the Kernel configures no Domains: give it a static domains() (ADR 0149).')];
        }

        try {
            $domains->serving($patterns);
        } catch (\InvalidArgumentException $e) {
            return [new Violation(self::HOSTS_MUST_BE_SERVED, 'Domains', $e->getMessage())];
        }

        return [];
    }

    /**
     * Discovery and its manifest, the build rules over every entry point
     * and payload, the config classes, the supply chain and the Caddyfile,
     * and the application's own checks; every failure named, under its
     * heading.
     */
    public function check(): int
    {
        // First what everything else is read from: the application's
        // classes, which a refused scan leaves nothing to check by -- scanned
        // afresh, never taken from the dev cache a scan keeps beside the build.
        try {
            $classes = Discovery::freshly($this->classes(...));
            $stale = $this->staleManifest();
        } catch (DiscoveryFailed $e) {
            fwrite(STDERR, "Discovery failed (ADR 0146):\n  " . implode("\n  ", $e->problems) . "\n");

            return 1;
        }

        $entryPoints = [...$classes->routes, ...$classes->queries, ...$classes->actions, ...$classes->endpoints, ...$classes->channels, ...$classes->transfers];
        $payloads = array_values(array_unique([...$classes->payloads, ...BuildRules::payloadsTakenBy($entryPoints)]));
        $results = [
            // A manifest in the build that a scan now disagrees with: what
            // production would boot from, missing a class or holding one
            // since gone.
            ['Discovery manifest stale (ADR 0146):', $stale, [$this->kernel === null ? 'ok: no Kernel to discover the classes of' : 'ok: ' . \count($classes->all()) . ' classes discovered, and the build\'s manifest, where there is one, lists the same']],
            // The pages and layouts, registered as the Kernel registers
            // them; the build rules over every entry point and every Data
            // object and form request one takes; and the config classes,
            // held as a boot holds them, with no variable read twice.
            ['Build rules failed:', [
                // Given the models, as the Kernel's registry is, so it
                // refuses a bare id to a tenant's model too (ADR 0105).
                ...RouteRegistry::check($classes->routes, new RouteRegistry($classes->models)),
                ...new BuildRules($classes->models)->check($entryPoints, $payloads),
                // The endpoints among them, compiled as the Kernel's registry
                // compiles them and each path held apart from every page's
                // (ADR 0067).
                ...self::endpointViolations($classes->endpoints, $classes->routes, $classes->models),
                // Every host an entry point declares, served by the hosts
                // the application configures, no two of them one host
                // (ADR 0149).
                ...self::hostViolations($classes->routes, $classes->endpoints, $classes->models, $this->domains()),
                // Sort and filter field enums held to their Data (ADR 0057);
                // who may subscribe to a channel (ADR 0069); a transfer's row
                // and body (ADR 0070).
                ...BuildRules::checkFieldEnums($classes->fieldEnums),
                ...ChannelRegistry::check($classes->channels),
                ...TransferRegistry::check($classes->transfers),
                // A notification's channels and defaults, and who may read
                // an inbox (ADR 0155).
                ...\Tinvar\Notifications\NotificationRegistry::check($classes->notifications),
                ...\Tinvar\Notifications\InboxRegistry::check($classes->inboxes),
                // The autoload map the rule finds an unlisted model in, read
                // whole -- or a failure saying where it stopped (ADR 0122).
                ...ApplicationClasses::fromComposer()->check(),
                ...Configs::check($classes->configs),
            ], [
                \sprintf('ok: %d entry points, %d payloads, no violations', \count($entryPoints), \count($payloads)),
                \sprintf('ok: %d config classes, every secret a Secret', \count($classes->configs)),
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
     * How the build's discovery manifest differs from a scan now, each
     * difference saying how to bring it up to date; none where there is no
     * Kernel, or no manifest in the build to differ.
     *
     * @return list<string>
     * @throws DiscoveryFailed where the scan is refused
     */
    private function staleManifest(): array
    {
        $discovery = $this->discovery();
        if ($discovery === null) {
            return [];
        }
        if (!is_file($discovery->manifest)) {
            $discovery->scan();

            return [];
        }

        return array_map(
            fn (string $difference): string => "{$difference}: `{$this->name} discover` writes it again, as `{$this->name} build` does",
            $discovery->stale(),
        );
    }

    /**
     * `discover`: a scan of the application's classes written to the
     * build's manifest, which production boots from (ADR 0146); with
     * --check, nothing written, and 1 where the manifest is missing or
     * differs from the scan.
     *
     * @param list<string> $arguments
     */
    private function discover(array $arguments): int
    {
        $check = false;
        $build = null;
        foreach ($arguments as $argument) {
            if ($argument === '--check') {
                $check = true;
            } elseif (str_starts_with($argument, '--build=') && \strlen($argument) > 8) {
                $build = rtrim(substr($argument, 8), '/');
            } else {
                return $this->usage('discover');
            }
        }
        $discovery = $this->discovery($build);
        if ($discovery === null) {
            fwrite(STDERR, "{$this->name} has no Kernel to discover the classes of.\n");

            return 1;
        }
        if ($check) {
            $stale = $discovery->stale();
            if ($stale !== []) {
                fwrite(STDERR, "{$discovery->manifest} is not what a scan finds now:\n  " . implode("\n  ", $stale) . "\n");

                return 1;
            }
            echo "ok: {$discovery->manifest} lists every class a scan finds\n";

            return 0;
        }
        $classes = $discovery->write();
        printf("wrote %s: %d classes under %s\\\n", $discovery->manifest, \count($classes->all()), $discovery->namespace);

        return 0;
    }

    /**
     * `build`: Vite's build for a profile, then -- for this application --
     * the fragment manifest and the discovery manifest beside it, which
     * production boots from.
     *
     * @param list<string> $arguments
     */
    private function build(array $arguments): int
    {
        $command = new ProfileBuildCommand($this->projectRoot === $this->root ? $this->root : (string) getcwd());
        $status = $command->run($arguments);
        if ($status !== 0 || $this->kernel === null) {
            return $status;
        }
        try {
            [, $app, $env] = $command->plan($arguments);
        } catch (\InvalidArgumentException|\RuntimeException) {
            return $status;
        }
        if ((realpath($app) ?: $app) !== $this->root) {
            return $status; // Another application's build: its own console discovers it.
        }

        // The fragment manifest, by the command registered under its name --
        // an application's own in the built-in's place -- since a build is
        // served from its directory alone, and with none there no render of
        // it may mint a boundary or call a query.
        $build = '--build=' . ($env[BuildProfile::BUILD_DIR_ENV] ?? "{$this->root}/dist");
        $fragments = ($this->commands['build:fragments']['run'])([$build]);
        if ($fragments !== 0) {
            return $fragments;
        }
        // The public API's description, beside what it describes (ADR 0155):
        // where codegen is installed and something is marked #[RequiresScope].
        if ($this->openapi !== null) {
            $described = ($this->openapi)(['--out=' . substr($build, \strlen('--build=')) . '/openapi.json', '--if-any']);
            if ($described !== 0) {
                return $described;
            }
        }

        return $this->discover([$build]);
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
     * Where a person is there, what MigrateCommand asks -- a rename, a fresh
     * migration or a rollback it was not forced to -- is a Prompts confirm,
     * no by default, and the migrations run under a progress bar, each line
     * they print above it.
     *
     * @param list<string> $arguments
     */
    public function migrate(string $command, array $arguments): int
    {
        $database = null;
        $rest = [];
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--database=')) {
                $database = substr($argument, 11);
            } else {
                $rest[] = $argument;
            }
        }
        // The application's own database, where it opens its own and no
        // --database=PATH names a SQLite file instead.
        $connect = $database === null ? $this->connect : null;
        $database ??= ($this->database)();
        $connection = $this->connection;
        $script = $this->name;

        // The bar the migrations run under, where one is drawn: what they
        // print goes above it.
        $running = new class {
            public ?Progress $bar = null;

            public function print(string $line, mixed $stream): void
            {
                $write = static function () use ($line, $stream): void {
                    \assert(\is_resource($stream));
                    fwrite($stream, $line . "\n");
                };
                $this->bar === null ? $write() : $this->bar->above($write);
            }
        };

        return new MigrateCommand(
            $connect ?? static fn (bool $create): Connection => $create || is_file($database)
                ? PdoConnection::sqlite($database, $connection, create: $create)
                : throw new MigrationRefused("No database at {$database}; `{$script} migrate` makes one."),
            $this->migrations,
            $this->classes()->models,
            out: static fn (string $line) => $running->print($line, STDOUT),
            // A failure stops the bar where it is, and is said under it.
            err: static function (string $line) use ($running): void {
                $running->bar?->release();
                $running->bar = null;
                $running->print($line, STDERR);
            },
            seeder: $this->seeder,
            ask: static fn (string $question): bool => Prompts::confirm($question, default: false),
            progress: static function (string $direction, int $done, int $total, string $name) use ($running): void {
                if ($done === 0) {
                    // Nothing to run -- a rollback with nothing to roll
                    // back is still told so -- draws no bar.
                    if ($total === 0) {
                        return;
                    }
                    $running->bar = Prompts::progress($direction === 'up' ? 'Migrating' : 'Rolling back', $total)->start();

                    return;
                }
                $running->bar?->hint($name)->advance();
                if ($done === $total) {
                    $running->bar?->finish();
                    $running->bar = null;
                }
            },
        )->run($command, $rest);
    }

    /**
     * Where the generators register a page's view: as given, or the route
     * table where there is one. The classes themselves are discovered once
     * written (ADR 0146): no list is edited.
     */
    public function registrations(): Registrations
    {
        if ($this->registrations !== null) {
            return $this->registrations;
        }
        $table = "{$this->root}/resources/entries/rsc.tsx";

        return $this->registrations = new Registrations(is_file($table) ? $table : null, $this->classes()->routes);
    }

    private function builtIns(): void
    {
        $this->command('check', 'check', "Run the security model's build rules over the app, hold its lockfiles to its PackagePolicy (no network), and its Caddyfile to holding each response whole and bounding a stalled write", fn (array $a): int => $a === [] ? $this->check() : $this->usage('check'))
            ->command('config', 'config', "Every key the app's #[Config] classes read, where each value comes from, and no secret's; exits 1, naming each key, where the server would refuse to boot", fn (array $a): int => new ConfigCommand($this->classes()->configs)->run($a))
            ->command('codegen', 'codegen [--out=DIR] [--check]', 'Write the TypeScript the views use from the PHP; with --check, write nothing and fail if what is there differs', $this->codegen ?? static function (): int {
                fwrite(STDERR, "tinvar/codegen is not installed: it is a dev dependency, which this install left out.\n");

                return 1;
            })
            ->command('openapi', 'openapi [--out=FILE] [--check] [--if-any]', "The public API -- every action, query and endpoint marked #[RequiresScope] -- as an OpenAPI 3.1 document; printed unless --out names a file, and with --check, compared with it", $this->openapi ?? static function (): int {
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
            ->command('build', 'build [--profile=production|staging|test] [--app=DIR] [--out=DIR]', 'The Vite build, made for a profile: production unless --profile says; each is served only under its own TINVAR_PROFILE. Then the fragment manifest and the discovery manifest beside it, which production boots from', $this->build(...))
            ->command('discover', 'discover [--check] [--build=DIR]', "Write every class the app registers, discovered under its namespace, to the build's manifest, which production boots from without a scan; with --check, write nothing and fail where the manifest is missing or stale", $this->discover(...))
            ->command('build:fragments', 'build:fragments [--build=DIR]', "Write the fragment manifest -- which routes may defer, and what each route's renders may query -- from the build and the routes", $this->fragments(...));
        foreach (['build:current' => 'build:current [<id>] | build:revoke <id> [--force] | build:floor <id> [--force] | build:list | build:prune [--keep=N]', 'build:revoke' => '', 'build:floor' => '', 'build:list' => '', 'build:prune' => ''] as $name => $usage) {
            $this->command($name, $usage, $usage === '' ? '' : 'The build history: what is released, and what is refused', fn (array $a): int => new BuildCommand(($this->buildStore)())->run($name, $a));
        }
        foreach (['renderer:start' => 'renderer:start | renderer:stop | renderer:check | renderer:restart', 'renderer:stop' => '', 'renderer:check' => '', 'renderer:restart' => ''] as $name => $usage) {
            $this->command($name, $usage, $usage === '' ? '' : 'The renderer pool, by hand', fn (array $a): int => new RendererCommand($this->projectRoot)->run($name, $a));
        }
        foreach ([
            'migrate' => ['migrate | migrate:rollback [--step=N] [--force] | migrate:status | migrate:fresh --force', 'The migrations, against the app\'s database (or --database=PATH)'],
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
                $i === 0 ? "make:page <Name> --path=/racks/{rack} [--view=racks/show]\n  make:query <Name> [--model=Rack]\n  make:action <Class> <method> [--model=Rack] [--request]\n  make:model <Name> [--table=racks] [--columns=name:string,heightU:int,notes:?string] [--factory] [--migration]\n  make:resource <Name> --fields=\"title:string,status:enum(Draft,Published),price:int,customer:belongsTo\" [--path=/orders] [--panel=admin]" : '',
                $i === 0 ? "Write a page, query, action or model, and what each needs -- or with make:resource, a model and its migration, queries, actions, pages, views, policy and tests at once -- which discovery registers once it is written, and a page's view in the route table where it reads as one; every check they write fails closed until you decide otherwise. Run bare on a terminal, each asks what it needs" : '',
                fn (array $a): int => $this->generate($name, $a),
            );
        }
        $this->command('doctor', 'doctor [--fix]', "Check everything the app needs to boot and serve -- PHP, Node, the key and probe token, config, proxies, hosts, mail, the database, the build, the renderers, file permissions and production's refusals -- and report every problem at once, each with its fix; --fix does the safe ones (a dev secret, a dev migration)", $this->doctor(...))
            ->command('deploy:init', DeployInitCommand::USAGE, "Write a deployment for Fly.io, Render, Railway or a VPS (systemd or Docker Compose): the platform's config with its edge's trusted-proxy ranges, the probes, the database and mail; generate its secrets into files outside the project, and print the steps that put them in the platform's secret store", fn (array $a): int => new DeployInitCommand(
                $this->root,
                array_filter(getenv(), \is_string(...)),
                static function (string $text): void {
                    echo $text;
                },
                static function (string $text): void {
                    fwrite(STDERR, $text);
                },
                $this->name,
            )->run($a))
            ->command('new', NewAppCommand::USAGE, "Make a new app from the skeleton -- asking the database, tenancy and how a tenant is found, the mail provider, whether to include accounts, and the deploy target -- then install, migrate and seed it, its secrets in files outside it; from this repository's checkout until tinvar/skeleton is published, then from Packagist", static fn (array $a): int => new NewAppCommand(
                static function (string $text): void {
                    echo $text;
                },
                static function (string $text): void {
                    fwrite(STDERR, $text);
                },
            )->run($a))
            ->command('kit:install', 'kit:install <package>', "Install a kit Composer installed -- a package declaring extra.tinvar.kit, as tinvar/accounts -- into the app: it writes the app's own classes, migrations and views once", $this->kitInstall(...))
            ->command('key:generate', 'key:generate [--show]', "Generate the app key and the probe token into their files outside the project where neither the environment nor the file has one, saying where and never what; --show prints a new key for a platform's secret store instead, and writes nothing", $this->keyGenerate(...));
        $this->command('packages:lock', 'packages:lock', "Pin each enabled package's version and files in tinvar.lock", fn (array $a): int => $this->packagesLock())
            ->command('audit', 'audit', 'composer audit and npm audit over the lockfiles, held to the reviewed exceptions in audit-exceptions.json (network)', fn (array $a): int => $this->audit());
    }

    /**
     * What `doctor` reads: this application, and the environment this
     * process runs in, read once.
     */
    public function doctorContext(): DoctorContext
    {
        $database = $this->database;
        $script = $this->name;

        return new DoctorContext(
            root: $this->root,
            env: array_filter(getenv(), \is_string(...)),
            console: $this->name,
            classes: $this->classes(...),
            connect: $this->connect ?? static function (bool $create) use ($database, $script): Connection {
                $path = $database();

                return $create || $path === ':memory:' || is_file($path)
                    ? PdoConnection::sqlite($path, 'doctor', create: $create)
                    : throw new MigrationRefused("No database at {$path}; `{$script} migrate` makes one.");
            },
            migrations: $this->migrations,
            keyFile: $this->secretFile($this->keyFile, 'keyFile'),
            probeTokenFile: $this->secretFile($this->probeTokenFile, 'probeTokenFile'),
            discovery: $this->discovery(),
            domains: $this->domains(...),
            migrate: fn (): int => $this->migrate('migrate', []),
            buildDirectory: BuildProfile::buildDirectory($this->root),
            projectRoot: $this->projectRoot,
        );
    }

    /**
     * Where a secret's file is: the closure the application gave, else its
     * Kernel's static $method where it has one; null where neither says,
     * or where it refuses (a relative TINVAR_APP_KEY_FILE, no state
     * directory), which `doctor` then reports from the environment.
     *
     * @param (\Closure(): ?string)|null $given
     */
    private function secretFile(?\Closure $given, string $method): ?string
    {
        try {
            if ($given !== null) {
                return $given();
            }
            $callable = [$this->kernel, $method];
            if ($this->kernel !== null && \is_callable($callable)) {
                $file = $callable();

                return \is_string($file) ? $file : null;
            }
        } catch (\RuntimeException) {
            return null;
        }

        return null;
    }

    /** @param list<string> $arguments */
    private function kitInstall(array $arguments): int
    {
        if (\count($arguments) !== 1 || str_starts_with($arguments[0], '-')) {
            return $this->usage('kit:install');
        }
        try {
            new KitInstaller($this->projectRoot, new KitTarget($this->root, $this->namespace, $this->name, $this->migrations), static function (string $text): void {
                echo $text;
            })->install($arguments[0]);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private function doctor(array $arguments): int
    {
        $fix = false;
        foreach ($arguments as $argument) {
            if ($argument !== '--fix') {
                return $this->usage('doctor');
            }
            $fix = true;
        }

        return new Doctor()->run($this->doctorContext(), $fix, static function (string $text): void {
            echo $text;
        });
    }

    /**
     * `key:generate`: the app key and the probe token, each generated into
     * its file where neither its variable nor the file has one, saying
     * where -- never what. Refused where the environment says production
     * or staging: a server's secrets are its secret store's, and two
     * servers generating their own would sign with two keys. `--show`
     * prints one new key for that store and writes nothing.
     *
     * @param list<string> $arguments
     */
    private function keyGenerate(array $arguments): int
    {
        if ($arguments === ['--show']) {
            echo SecretFiles::fresh(), "\n";

            return 0;
        }
        if ($arguments !== []) {
            return $this->usage('key:generate');
        }
        $context = $this->doctorContext();
        $production = $context->production();
        if ($production !== null) {
            fwrite(STDERR, "key:generate writes no key on a server ({$production}): set TINVAR_APP_KEY and TINVAR_PROBE_TOKEN from the platform's secret store; `{$this->name} key:generate --show` prints a new value for each.\n");

            return 1;
        }

        $status = 0;
        foreach ([SecretFiles::APP_KEY => ['the app key', $context->keyFile], SecretFiles::PROBE_TOKEN => ['the probe token', $context->probeTokenFile]] as $variable => [$what, $file]) {
            if ($file === null) {
                if (($context->env[$variable] ?? '') !== '') {
                    echo "{$what}: given by {$variable}\n";

                    continue;
                }
                fwrite(STDERR, "{$what}: this app names no file for it, and {$variable} is not set; set {$variable}, or {$variable}_FILE to an absolute path outside the project.\n");
                $status = 1;

                continue;
            }
            try {
                $done = SecretFiles::ensure($variable, $file, $context->env);
            } catch (\RuntimeException $e) {
                fwrite(STDERR, "{$what}: {$e->getMessage()}\n");
                $status = 1;

                continue;
            }
            echo match ($done) {
                'environment' => "{$what}: given by {$variable}; no file is used\n",
                'kept' => "{$what}: {$file} (already there, kept)\n",
                'generated' => "{$what}: generated {$file}\n",
            };
        }

        return $status;
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

        return new MakeCommand($layout, $this->classes()->models, registrations: $this->registrations(), migrate: $this->makeMigration(...), migrations: $this->migrations, resourceTest: $this->resourceTest)->run($command, $arguments);
    }

    /**
     * make:migration $name, run as this console in a process of its own --
     * so it loads the model make:model has just written and registered,
     * which this one never loaded -- on this terminal, to ask what it asks.
     */
    private function makeMigration(string $name): int
    {
        $command = [...PhpCommand::prefix(), $this->console, 'make:migration', $name];
        if (!Prompts::interactive()) {
            $command[] = '--no-interaction';
        }
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

        return \is_resource($process) ? proc_close($process) : 1;
    }

    /**
     * The fragment manifest, from the build's deferred.json and the routes;
     * part of the build, after Vite.
     *
     * @param list<string> $arguments
     */
    private function fragments(array $arguments): int
    {
        // The build discover writes into: TINVAR_BUILD_DIR's, or dist/.
        $build = null;
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--build=') || rtrim(substr($argument, 8), '/') === '') {
                return $this->usage('build:fragments');
            }
            $build = rtrim(substr($argument, 8), '/');
        }
        $build ??= BuildProfile::buildDirectory($this->root);

        $routes = new RouteRegistry();
        $routes->registerAll($this->classes()->routes);
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

    /**
     * The advisory databases' view of both lockfiles, held to the reviewed
     * exceptions in audit-exceptions.json (Advisories, ADR 0137). Needs the
     * network.
     */
    private function audit(): int
    {
        $lockfiles = new Lockfiles($this->projectRoot);
        $answers = ['composer' => null, 'npm' => null];
        foreach ([
            'composer' => [$lockfiles->hasComposer(), ['composer', 'audit', '--locked', '--no-interaction', '--format=json']],
            'npm' => [$lockfiles->hasNpm(), ['npm', 'audit', '--json']],
        ] as $ecosystem => [$present, $command]) {
            if (!$present) {
                $answers[$ecosystem] = null;

                continue;
            }
            echo '$ ' . implode(' ', $command) . "\n";
            $process = proc_open($command, [0 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $this->projectRoot);
            if (!\is_resource($process)) {
                $answers[$ecosystem] = ['', 127];

                continue;
            }
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $answers[$ecosystem] = [$out, proc_close($process)];
        }

        $verdict = new Advisories($this->projectRoot, new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->judge($answers['composer'], $answers['npm']);
        foreach ($verdict['excused'] as $line) {
            echo "excused: {$line}\n";
        }
        if ($verdict['failures'] !== []) {
            fwrite(STDERR, "Audit failed:\n  " . implode("\n  ", $verdict['failures']) . "\n");

            return 1;
        }
        echo 'ok: no advisory on what is locked that ' . Advisories::FILE . " does not excuse\n";

        return 0;
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
