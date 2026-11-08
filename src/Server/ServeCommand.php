<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Config\ConfigException;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Http\TrustedProxies;
use Tinvar\Jobs\StopBudget;
use Tinvar\Observability\RendererLines;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Queue\HeldJob;
use Tinvar\Render\RendererException;
use Tinvar\Render\RendererHealth;
use Tinvar\Render\RendererSockets;
use Tinvar\Render\RendererUser;
use Tinvar\Render\RenderFailure;
use Tinvar\Render\SocketTrust;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\ChildDescriptors;
use Tinvar\Support\DevMode;
use Tinvar\Support\PrivateDirectory;

/**
 * `tinvar serve`: an app's renderers and FrankenPHP, started together and
 * stopped together.
 *
 *   tinvar serve [--dev] [--host=HOST] [--port=PORT] [--app=DIR] [--workers=N] [--renderers=N]
 *                [--frankenphp=PATH] [--worker] [--schedule] [--public-host=NAME[,NAME...] [--share]]
 *
 * With --dev, Vite's dev server is the renderer ("Dev mode: Vite's dev server
 * is the renderer, with HMR and no separate build"): no build is needed, the
 * renderer renders the app from source, and FrankenPHP -- in classic mode, so
 * an edit to the PHP is in the next response too -- proxies the dev server's
 * modules and HMR socket under /_vite/ on its own origin, by the framework's
 * resources/Caddyfile.dev. Refused, loudly, wherever the environment says
 * production, and on any address but loopback. With --public-host it also
 * answers the names a tunnel serves it under; with --share, only to a
 * visitor holding the share token it prints (PublicHost, ADR 0190, 0195).
 *
 * The app is a directory with a Caddyfile and a build: the command knows
 * nothing about any particular app. It passes the app what it decided as
 * environment variables its Caddyfile reads -- TINVAR_HOST, TINVAR_PORT,
 * TINVAR_APP_ROOT, TINVAR_WORKERS, and TINVAR_SOCKET, the renderers the PHP
 * workers talk to -- so the Caddyfile says how the app is served and this
 * says where.
 *
 * The renderers are a pool (ADR 0028): one supervising process and N
 * sandboxed renderers, one socket each, in a fresh private directory under
 * /tmp -- short enough for macOS's 104-byte limit, and one no other user can
 * reach into. TINVAR_SOCKET names the directory, and each render goes to one
 * of the sockets in it. In dev mode Vite's dev server is the one renderer,
 * on one socket there. The renderers get a scrubbed environment, as they
 * require; FrankenPHP gets this process's, since the application reads its
 * settings from it.
 *
 * With TINVAR_RENDERER_USER set, the renderers run as a user of their own
 * (ADR 0028), and are not this command's to start: no process here can
 * change user, and none should run as root to. The service manager starts
 * the pool as that user (renderer.js with --socket-group, as its User=),
 * TINVAR_SOCKET names the pool's directory, and serve starts no
 * renderers: it checks the pool there as a render would -- answered by that
 * user's processes and no one else's -- before FrankenPHP starts, and
 * refuses dev mode and --renderers, which would be renderers of its own.
 *
 * `--worker` and `--schedule` start the application's queue worker
 * (`queue:work`) and scheduler (`schedule:work`) beside them, through the
 * console this command is run from, with FrankenPHP's environment and the
 * URL it serves (TINVAR_URL), which work done outside a request builds its
 * links on. Either one exiting on its own -- a worker giving up its process
 * to a job that would not stop at its timeout, or past its memory limit --
 * is started again, up to RESTARTS times a minute; past that, or when the
 * renderer or FrankenPHP exits, everything stops.
 *
 * It prints the URL once FrankenPHP is answering on it, forwards SIGINT and
 * SIGTERM to the children, and if the renderer or FrankenPHP exits on its
 * own stops the rest and exits non-zero. Stopping, it stops the queue
 * worker and the scheduler first, given time to finish the job in hand,
 * then FrankenPHP, and the renderers last, so the jobs and requests the
 * others finish still have a server and a renderer (stopOrder()). SIGUSR2 is passed to the pool: a rolling restart, after a
 * deploy. Killed outright, it can do none of that, so each child
 * also stops when this process's end of its standard input closes; and the
 * next start removes the directory a killed one left.
 *
 * Given the application's log (RendererLines), the renderers' output is
 * read here rather than inherited: each line still goes to standard error
 * as it was written, and into the application's log beside PHP's, under
 * the request id it carries (ADR 0128). The renderers still write nothing
 * themselves; this process, which holds the log's path and none of their
 * sandbox, does.
 */
final class ServeCommand
{
    public const string DEFAULT_HOST = '127.0.0.1';

    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    private const array OPTIONS = ['host', 'port', 'app', 'workers', 'renderers', 'frankenphp', 'public-host'];

    /** More would be more processes than any machine this runs on has cores for. */
    public const int MAX_RENDERERS = 64;

    /** Options that take no value: dev mode, and the background children to start beside the server. */
    private const array FLAGS = ['dev', 'worker', 'schedule', 'share'];

    /**
     * What only `serve --dev` hands FrankenPHP: kept out of the environment of
     * every serve that is not, so none left over in a shell or an env file
     * turns dev mode on, or points a proxy at whatever holds the port.
     */
    private const array DEV_ENV = ['TINVAR_DEV', 'TINVAR_VITE_PORT', 'TINVAR_DEFERRED_MANIFEST', PublicHost::HOST_ENV, PublicHost::SHARED_ENV, PublicHost::TOKEN_ENV];

    /** The prefix of the private directory each serve runs in (PrivateDirectory). */
    public const string RUN_PREFIX = 'tinvar-serve';

    /** The dev renderer's socket, in that directory. */
    public const string DEV_SOCKET = 'renderer.sock';

    /** The deferred manifest the dev renderer writes beside its socket. */
    public const string DEV_DEFERRED = 'deferred.json';

    /**
     * What a serve --dev says of itself in its directory, once its dev
     * renderer listens: the application it serves. `render --dump --dev`
     * and `mcp --dev` find the run by it (ADR 0132).
     */
    public const string DEV_RECORD = 'dev.json';

    /** The addresses dev mode may listen on. */
    private const array LOOPBACK = ['127.0.0.1', '::1'];

    /** How often in a minute a background child may be started again before serve gives up on it. */
    public const int RESTARTS = 5;

    /**
     * What dev mode's renderer is told beside RendererBinary::ENV: which environment
     * and profile this is, none of it secret, so the dev server's workbench
     * mounts only where TINVAR_ENV says this is a developer's machine (ADR
     * 0089), and its email preview sees a production marker for itself.
     */
    private const array DEV_RENDERER_ENV = ['TINVAR_ENV', 'APP_ENV', 'TINVAR_PROFILE'];

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    private bool $stopping = false;

    /** Set by SIGUSR2, and passed on to the renderers by supervise(). */
    private bool $restartRequested = false;

    /**
     * The write end of each child's standard input, by process: held open
     * and never written to. See spawn().
     *
     * @var array<int, resource>
     */
    private array $tethers = [];

    /**
     * The renderers' standard output and error, read here when they are
     * written to the application's log too, by stream id.
     *
     * @var array<int, array{resource, bool}> the pipe, and whether it is standard error
     */
    private array $outputs = [];

    /**
     * @param string $projectRoot where the framework's node_modules and tools are
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     * @param (\Closure(): array<string, string>)|null $serverEnvironment more of
     *        FrankenPHP's environment, from the application -- its live hub's
     *        keys, which its Caddyfile reads (Tinvar\Live\HubSettings). Asked
     *        once FrankenPHP is about to start, and never given to the
     *        renderer, whose environment stays scrubbed.
     * @param string|null $console the CLI whose `queue:work` and `schedule:work`
     *        --worker and --schedule run; without it, neither is offered
     * @param RendererLines|null $rendererLog the application's log, which the
     *        renderers' lines are written to as well as to standard error;
     *        without it, they go to standard error alone
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly PortFinder $ports = new PortFinder(),
        ?\Closure $out = null,
        ?\Closure $err = null,
        private readonly ?string $workingDirectory = null,
        private readonly ?\Closure $serverEnvironment = null,
        private readonly ?string $console = null,
        private readonly ?RendererLines $rendererLog = null,
    ) {
        $this->out = $out ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
            fflush(STDOUT);
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $plan = $this->plan($arguments);
        } catch (ServeException $e) {
            ($this->err)($e->getMessage());

            return self::USAGE;
        }

        return $this->serve($plan);
    }

    /**
     * Everything decided before anything starts, so a mistake is reported
     * without a process to clean up.
     *
     * @param list<string> $arguments
     * @return array{host: string, port: int, app: string, entry: string|null, renderer: string, frankenphp: string, workers: int, dev: bool, vitePort: int|null, publicHost: PublicHost|null, caddyfile: string, background: list<string>, renderers: int, pool: string|null, stopBudget: int}
     */
    public function plan(array $arguments): array
    {
        $options = self::parse($arguments);
        $dev = isset($options['dev']);

        // First, before anything is looked at: dev mode never runs where the
        // environment says production.
        if ($dev) {
            $marker = DevMode::productionMarker(array_filter(getenv(), \is_string(...)));
            if ($marker !== null) {
                throw new ServeException(
                    "tinvar serve --dev is refused: {$marker}=production. Dev mode runs Vite's dev server unsandboxed "
                    . 'and shows raw errors to the browser; it never runs in production. Serve a build there.',
                );
            }
        }

        // The renderers' own user, where they run as one: then they are the
        // service manager's, in the pool TINVAR_SOCKET names, and nothing
        // here may start a renderer as this user in their place.
        $pool = null;
        try {
            $user = RendererUser::fromEnvironment();
            $user?->assertNotThisProcess();
        } catch (\InvalidArgumentException|ConfigException $e) {
            throw new ServeException($e->getMessage());
        }
        if ($user !== null) {
            if ($dev) {
                throw new ServeException(
                    RendererUser::ENV . " says the renderers run as {$user->name}, and dev mode's renderer would run as this user. "
                    . 'Unset it to develop.',
                );
            }
            if (isset($options['renderers'])) {
                throw new ServeException(
                    RendererUser::ENV . " says the renderers run as {$user->name}, started by their service manager; "
                    . 'serve starts none, so --renderers has nothing to size. Set the pool\'s size where it is started.',
                );
            }
            $pool = getenv('TINVAR_SOCKET');
            if (!\is_string($pool) || !str_starts_with($pool, '/')) {
                throw new ServeException(
                    RendererUser::ENV . " says the renderers run as {$user->name}; set TINVAR_SOCKET to the absolute path of "
                    . 'the directory their pool runs in.',
                );
            }
            $pool = rtrim($pool, '/');
        }

        $host = $options['host'] ?? self::DEFAULT_HOST;
        PortFinder::assertHost($host);
        // The test profile's endpoint binds to loopback (tinvar-spec.md,
        // "Testing"): refused here, as anywhere, beside production or where
        // nothing says otherwise, and on any other address. Each request it
        // answers is held to loopback again, with the run's token.
        try {
            $test = FrameworkConfig::load()->buildProfile() === BuildProfile::TEST;
        } catch (\RuntimeException $e) {
            throw new ServeException($e->getMessage());
        }
        if ($test && !\in_array($host, self::LOOPBACK, true)) {
            throw new ServeException('TINVAR_PROFILE=test serves the browser test endpoint, on loopback only (127.0.0.1 or ::1); refusing ' . $host . '.');
        }
        if ($dev && !\in_array($host, self::LOOPBACK, true)) {
            throw new ServeException("tinvar serve --dev listens on loopback only (127.0.0.1 or ::1); refusing {$host}.");
        }
        // A tunnel's name, which only dev's Caddyfile knows to let in, and
        // to hold to the share token (ADR 0190).
        if (isset($options['public-host']) && !$dev) {
            throw new ServeException('--public-host is for --dev: a build is served by its own Caddyfile, under the names it gives.');
        }
        if (isset($options['share']) && !isset($options['public-host'])) {
            throw new ServeException('--share holds the names --public-host gives to a share token; it names none.');
        }
        $publicHost = isset($options['public-host']) ? PublicHost::of($options['public-host'], shared: isset($options['share'])) : null;

        $app = $this->app($options['app'] ?? null, requireCaddyfile: !$dev);
        // The build: dist/ in the app, unless TINVAR_BUILD_DIR names one of
        // its own -- a test build beside it (BuildProfile, ADR 0066).
        try {
            $entry = $dev ? null : BuildProfile::buildDirectory($app) . '/rsc/index.js';
        } catch (\RuntimeException $e) {
            throw new ServeException($e->getMessage());
        }
        if ($entry !== null && !is_file($entry)) {
            throw new ServeException("{$app} is not built: there is no {$entry}. Build it first (npx vite build in {$app}).");
        }
        if ($dev && !is_file("{$app}/public/index.php")) {
            throw new ServeException("{$app} has no public/index.php, which dev mode runs for every request.");
        }

        $workers = $options['workers'] ?? null;
        if ($workers !== null && (preg_match('/^[1-9][0-9]{0,2}\z/', $workers) !== 1 || (int) $workers > 256)) {
            throw new ServeException("--workers must be a whole number from 1 to 256; got '{$workers}'.");
        }
        $workers = $workers === null ? self::cpus() : (int) $workers;

        $renderers = $options['renderers'] ?? null;
        if ($renderers !== null && (preg_match('/^[1-9][0-9]?\z/', $renderers) !== 1 || (int) $renderers > self::MAX_RENDERERS)) {
            throw new ServeException('--renderers must be a whole number from 1 to ' . self::MAX_RENDERERS . "; got '{$renderers}'.");
        }
        // Dev mode renders through Vite's one dev server, from source; a pool
        // is of built renderers.
        if ($dev && $renderers !== null) {
            throw new ServeException("--renderers runs a pool of built renderers; --dev renders through Vite's one dev server.");
        }

        $frankenphp = FrankenPhp::locate($this->projectRoot, $options['frankenphp'] ?? null);
        // Not looked for when the renderers' own user runs them: their image
        // may be the only one with the renderer in it (ADR 0071).
        $renderer = $pool === null ? $this->rendererBin($dev ? 'dev-renderer.js' : 'renderer.js') : '';

        // Last: choosing a port binds it for a moment, which is only worth
        // doing once everything else is known to be there.
        $port = $this->ports->find($host, $options['port'] ?? null);
        $vitePort = null;
        if ($dev) {
            // Its own, which is never the one PHP's server takes.
            do {
                $vitePort = $this->ports->find($host);
            } while ($vitePort === $port);
        }

        return [
            'host' => $host,
            'frankenphp' => $frankenphp,
            'app' => $app,
            'entry' => $entry,
            'renderer' => $renderer,
            'workers' => $workers,
            'renderers' => $dev ? 1 : ($renderers === null ? self::defaultRenderers(self::cpus(), $workers) : (int) $renderers),
            'pool' => $pool,
            'background' => $this->background($options),
            'stopBudget' => $this->stopBudget(),
            'port' => $port,
            'dev' => $dev,
            'vitePort' => $vitePort,
            'publicHost' => $publicHost,
            'caddyfile' => $dev ? \dirname(__DIR__, 2) . '/resources/Caddyfile.dev' : "{$app}/Caddyfile",
        ];
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string> options by name; a flag's value is ''
     */
    private static function parse(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z]+)\z/', $argument, $m) === 1 && \in_array($m[1], self::FLAGS, true)) {
                $options[$m[1]] = '';
                continue;
            }
            if (preg_match('/^--([a-z]+(?:-[a-z]+)*)=(.*)\z/s', $argument, $m) !== 1 || !\in_array($m[1], self::OPTIONS, true)) {
                throw new ServeException(
                    "Unknown argument '{$argument}'. Usage: tinvar serve [--dev] [--host=HOST] [--port=PORT] [--app=DIR] "
                    . '[--workers=N] [--renderers=N] [--frankenphp=PATH] [--worker] [--schedule] [--public-host=NAME[,NAME...] [--share]]',
                );
            }
            $options[$m[1]] = $m[2];
        }

        return $options;
    }

    /**
     * How long the queue worker and the scheduler are given to stop
     * (TINVAR_STOP_BUDGET): the worker reads the same, from the environment
     * it is started with, and hands back a job still running before this
     * kills it.
     */
    private function stopBudget(): int
    {
        try {
            return StopBudget::fromEnvironment();
        } catch (\InvalidArgumentException $e) {
            throw new ServeException($e->getMessage());
        }
    }

    /**
     * The console commands --worker and --schedule ask for.
     *
     * @param array<string, string> $options
     * @return list<string>
     */
    private function background(array $options): array
    {
        $commands = [];
        if (isset($options['worker'])) {
            $commands[] = 'queue:work';
        }
        if (isset($options['schedule'])) {
            $commands[] = 'schedule:work';
        }
        if ($commands !== [] && ($this->console === null || !is_file($this->console))) {
            throw new ServeException('--worker and --schedule run the application\'s console, and this serve was given none.');
        }

        return $commands;
    }

    /**
     * The app's directory: --app, or the working directory if it has a
     * Caddyfile -- which dev mode, serving every app by the framework's own,
     * does not ask for.
     */
    private function app(?string $given, bool $requireCaddyfile = true): string
    {
        $cwd = $this->workingDirectory ?? (string) getcwd();
        $candidate = $given === null ? $cwd : (str_starts_with($given, '/') ? $given : "{$cwd}/{$given}");
        $app = realpath($candidate);

        if ($app === false || !is_dir($app)) {
            throw new ServeException("No app at {$candidate}.");
        }
        if ($requireCaddyfile && !is_file("{$app}/Caddyfile")) {
            throw new ServeException(
                $given === null
                    ? 'No app to serve here: run from an app directory with a Caddyfile, or pass --app=DIR.'
                    : "{$app} has no Caddyfile, so there is nothing to tell FrankenPHP how to serve it.",
            );
        }

        return $app;
    }

    private function rendererBin(string $bin): string
    {
        return RendererBinary::script($this->projectRoot, $bin);
    }

    /** @param array{host: string, port: int, app: string, entry: string|null, renderer: string, frankenphp: string, workers: int, dev: bool, vitePort: int|null, publicHost: PublicHost|null, caddyfile: string, background: list<string>, renderers: int, pool: string|null, stopBudget: int} $plan */
    private function serve(array $plan): int
    {
        $this->stopBudget = $plan['stopBudget'];
        // One killed outright had no chance to remove its own.
        PrivateDirectory::removeAbandoned(self::RUN_PREFIX);
        $directory = PrivateDirectory::create(self::RUN_PREFIX, owner: getmypid() ?: null);
        $this->held = "{$directory}/queue-work.held";
        $socket = "{$directory}/" . self::DEV_SOCKET;
        $deferred = "{$directory}/" . self::DEV_DEFERRED;
        $children = [];

        $this->trapSignals();

        $rendererEnv = RendererBinary::environment();
        // What FrankenPHP is told on top of this process's environment and the
        // application's (serverEnvironment, asked as FrankenPHP starts).
        $phpEnv = [
            'TINVAR_HOST' => $plan['host'],
            'TINVAR_PORT' => (string) $plan['port'],
            'TINVAR_APP_ROOT' => $plan['app'],
            // Where the Caddyfile serves the client's assets from, and the
            // application reads its build: the one the renderers load.
            BuildProfile::BUILD_DIR_ENV => $plan['entry'] === null ? "{$plan['app']}/dist" : \dirname($plan['entry'], 2),
            'TINVAR_WORKERS' => (string) $plan['workers'],
            // The pool's directory -- this serve's, or the one the renderers'
            // own user runs -- and dev mode's one socket.
            'TINVAR_SOCKET' => $plan['dev'] ? $socket : ($plan['pool'] ?? $directory),
            // The proxies TINVAR_TRUSTED_PROXIES pins, space-separated, for
            // the Caddyfile's `trusted_proxies static`: Caddy then logs and
            // forwards the client PHP's throttles count (ADR 0134). Always
            // set, from the list alone, so a stray value in the environment
            // is not what Caddy trusts.
            TrustedProxies::CADDY_ENV => FrameworkConfig::load()->proxies()->caddyRanges(),
        ];

        try {
            if ($plan['pool'] !== null) {
                // Run as a user of their own, by their service manager: held
                // to that user before anything here relies on them.
                $plan['renderers'] = $this->awaitPool($plan['pool']);
            } elseif ($plan['dev']) {
                // The dev server keeps the views' <Deferred> components up to
                // date beside the socket, as a build writes them, so a route
                // mints in dev exactly when its build would let it.
                $children['the dev renderer'] = $renderer = $this->spawn(
                    [
                        'node', $plan['renderer'], $socket, $plan['app'], "--port={$plan['vitePort']}", "--deferred={$deferred}", '--exit-with-stdin',
                        // The tunnel's name, which Vite's own host check lets in too.
                        ...($plan['publicHost'] === null ? [] : ['--public-host=' . implode(',', $plan['publicHost']->hosts)]),
                    ],
                    $plan['app'],
                    [...$rendererEnv, ...array_intersect_key(getenv(), array_flip(self::DEV_RENDERER_ENV)), 'NODE_ENV' => 'development'],
                    logged: true,
                );
                // Longer than a built renderer's start: Vite optimizes the
                // app's dependencies the first time, and the socket appears
                // only once the ssr environment and every client component
                // are loaded -- the shop's some 2,000 modules, which took 45
                // to 55 s on a loaded machine and 90 s on one far past it.
                $this->waitFor(static fn (): bool => file_exists($socket), $renderer, 'The dev renderer', 180);
                // Which application this run serves, for the dev tools that
                // render through its dev renderer: the record names the app
                // alone, and they take the socket from where it is, here.
                file_put_contents("{$directory}/" . self::DEV_RECORD, json_encode(['app' => $plan['app']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                chmod("{$directory}/" . self::DEV_RECORD, 0600);
                $phpEnv = [
                    ...$phpEnv,
                    'TINVAR_DEV' => '1',
                    'TINVAR_VITE_PORT' => (string) $plan['vitePort'],
                    'TINVAR_DEFERRED_MANIFEST' => $deferred,
                    ...($plan['publicHost']?->environment() ?? []),
                ];
            } else {
                // The pool's directory is the private one: PHP lists it for
                // sockets, and it holds nothing else they could be confused with.
                $children['the renderers'] = $renderer = $this->spawn(
                    ['node', $plan['renderer'], $directory, (string) $plan['entry'], '--exit-with-stdin', "--processes={$plan['renderers']}"],
                    $plan['app'],
                    [...$rendererEnv, 'NODE_ENV' => 'production'],
                    logged: true,
                );
                $this->waitFor(
                    static fn (): bool => self::socketsIn($directory) >= $plan['renderers'],
                    $renderer,
                    'The renderers',
                    30,
                );
            }

            // FrankenPHP's environment, which the queue worker and scheduler
            // share: the application reads its settings from it, and work
            // done outside a request builds its links on TINVAR_URL.
            $environment = [
                ...array_diff_key(getenv(), array_flip(self::DEV_ENV)),
                ...($this->serverEnvironment === null ? [] : ($this->serverEnvironment)()),
                ...$phpEnv,
                'TINVAR_URL' => \sprintf('http://%s:%d', PortFinder::hostForUrl($plan['host']), $plan['port']),
            ];

            // FrankenPHP watches nothing of its parent's, so it runs under a
            // PHP process that watches standard input for it.
            $children['FrankenPHP'] = $server = $this->spawn(
                [
                    ...PhpCommand::prefix(),
                    __DIR__ . '/../../bin/tethered.php',
                    '--kill-after=' . self::tetherKillAfter(self::STOP_SECONDS),
                    $plan['frankenphp'],
                    'run',
                    '--config',
                    $plan['caddyfile'],
                    '--adapter',
                    'caddyfile',
                ],
                $plan['app'],
                $environment,
            );
            $this->waitFor(static fn (): bool => PortFinder::answers($plan['host'], $plan['port']), $server, 'FrankenPHP', 30);

            // Once the server answers, so a job that publishes to its hub, or
            // a link it builds, finds it there.
            foreach ($plan['background'] as $command) {
                $children[$command] = $this->spawnBackground($command, $plan['app'], $environment);
            }
        } catch (ServeException $e) {
            ($this->err)($e->getMessage());
            $this->terminate($children);
            PrivateDirectory::remove($directory);

            return 1;
        } catch (\Throwable $e) {
            // Anything else -- the application's server environment, asked
            // once the renderers are up, failing to read a key -- is still a
            // start that stops here: what it started is stopped and its
            // directory removed before the failure goes on to the caller.
            $this->terminate($children);
            PrivateDirectory::remove($directory);

            throw $e;
        }

        ($this->out)(sprintf('Listening on http://%s:%d', PortFinder::hostForUrl($plan['host']), $plan['port']));
        ($this->err)($plan['dev']
            ? sprintf(
                'Serving %s in dev: Vite\'s dev server on port %d renders it from source, its modules and HMR '
                . 'proxied under /_vite/; FrankenPHP runs the PHP afresh on every request. Ctrl+C stops both.',
                $plan['app'],
                $plan['vitePort'],
            )
            : sprintf(
                'Serving %s with %d FrankenPHP workers and %d %s in %s. Ctrl+C stops both.',
                $plan['app'],
                $plan['workers'],
                $plan['renderers'],
                $plan['renderers'] === 1 ? 'renderer' : 'renderers',
                $plan['pool'] === null ? $directory : "{$plan['pool']}, run as " . (string) FrameworkConfig::load()->rendererUserName(),
            ));
        if ($plan['publicHost'] !== null) {
            // Each on its own line of standard output, as the URL is: what a
            // caller hands on, and, where shared, the only place the token
            // is ever said.
            foreach ($plan['publicHost']->shareUrls() as $url) {
                ($this->out)("Shared at {$url}");
            }
            $names = implode(', ', $plan['publicHost']->hosts);
            ($this->err)($plan['publicHost']->shared()
                ? "Anyone with that address reaches this dev server -- the app's source, its PHP included, and every "
                    . "page's props -- until it stops. {$names} without the token is refused."
                : "Anyone who reaches {$names} reaches this dev server -- the app's source, its PHP included, and every "
                    . "page's props -- with no token asked for. Keep the tunnel to whom you choose (Cloudflare Access), "
                    . 'or pass --share.');
        }

        $status = $this->supervise($children, $plan['app'], $environment);
        $this->terminate($children, $plan['app'], $environment);
        PrivateDirectory::remove($directory);

        return $status;
    }

    /**
     * Waits for a stop signal or for a child to exit on its own. A queue
     * worker or scheduler that exits is started again, within RESTARTS a
     * minute; anything else exiting stops everything.
     *
     * @param array<string, resource> $children
     * @param array<string, string>   $environment the background children's
     */
    private function supervise(array &$children, string $app, array $environment): int
    {
        /** @var array<string, list<float>> $restarts */
        $restarts = [];
        for (;;) {
            if ($this->stopRequested()) {
                return 0;
            }

            // The pool's supervisor restarts its renderers one at a time on
            // SIGUSR2 (renderer:restart sends it there directly).
            if ($this->restartRequested) {
                $this->restartRequested = false;
                if (isset($children['the renderers']) && proc_get_status($children['the renderers'])['running']) {
                    proc_terminate($children['the renderers'], \defined('SIGUSR2') ? SIGUSR2 : 31);
                    ($this->err)('Restarting the renderers one at a time.');
                } elseif (!isset($children['the renderers']) && !isset($children['the dev renderer'])) {
                    ($this->err)('The renderers run as their own user, under their service manager: reload them there (it sends their supervisor SIGUSR2).');
                }
            }

            foreach ($children as $name => $process) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    continue;
                }
                // A Ctrl+C reaches the children too, and one may exit before
                // the signal reaches this loop: that is a stop, not a crash.
                usleep(50_000);
                if ($this->stopRequested()) {
                    return 0;
                }

                $recent = array_values(array_filter($restarts[$name] ?? [], static fn (float $at): bool => $at > microtime(true) - 60));
                if (\in_array($name, self::BACKGROUND, true) && \count($recent) < self::RESTARTS) {
                    ($this->err)("{$name} exited with status {$status['exitcode']}; starting it again.");
                    $this->release($process);
                    $restarts[$name] = [...$recent, microtime(true)];
                    $children[$name] = $this->spawnBackground($name, $app, $environment);

                    continue;
                }

                ($this->err)("{$name} exited with status {$status['exitcode']}; stopping the rest.");

                return 1;
            }

            $this->pump(0.1);
        }
    }

    /** The children supervise() starts again when they exit. */
    private const array BACKGROUND = ['queue:work', 'schedule:work'];

    /**
     * Seconds the queue worker and the scheduler are given to stop unless
     * TINVAR_STOP_BUDGET says otherwise: a job at the default timeout
     * (#[Queue]'s 60 s), the grace the worker gives it past that, and a
     * margin (StopBudget::DEFAULT).
     */
    public const int BACKGROUND_STOP_SECONDS = StopBudget::DEFAULT;

    /** The plan's stop budget, once serve() has it: what terminate() gives the background. */
    private int $stopBudget = StopBudget::DEFAULT;

    /** Where the queue worker writes the job in hand (HeldJob), in serve's private directory. */
    private ?string $held = null;

    /** Seconds `queue:hand-back` is given, beside FrankenPHP's stop. */
    public const int HAND_BACK_SECONDS = 5;

    /** Seconds FrankenPHP, and then the renderers, are given to stop. */
    public const int STOP_SECONDS = 10;

    /**
     * A console command beside the server, tethered as FrankenPHP is: killed
     * outright, serve closes its end of the pipe and the command is stopped.
     * SIGTERM reaches it through the tether, so a worker finishes the job in
     * hand before it goes.
     *
     * @param array<string, string> $environment
     * @return resource
     */
    private function spawnBackground(string $command, string $app, array $environment)
    {
        \assert($this->console !== null);

        $php = PhpCommand::prefix();
        if ($command === 'queue:work' && $this->held !== null) {
            $environment[HeldJob::ENV] = $this->held;
        }

        return $this->spawn(
            [...$php, __DIR__ . '/../../bin/tethered.php', '--kill-after=' . self::tetherKillAfter($this->stopBudget), ...$php, $this->console, $command],
            $app,
            $environment,
        );
    }

    /**
     * How long a tether gives its command after a stop before it kills it:
     * inside the $seconds serve gives it before killing the tether, which
     * can then pass nothing on, so the command never outlives it.
     */
    public static function tetherKillAfter(int $seconds): int
    {
        return max(1, $seconds - 2);
    }

    /** @param resource $process */
    private function release($process): void
    {
        if (isset($this->tethers[(int) $process])) {
            fclose($this->tethers[(int) $process]);
            unset($this->tethers[(int) $process]);
        }
        proc_close($process);
    }

    /**
     * Whether a stop signal has arrived. A handler sets it at any moment, so
     * two calls in a row can answer differently.
     *
     * @phpstan-impure
     */
    private function stopRequested(): bool
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $this->stopping;
    }

    private function trapSignals(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }
        pcntl_signal(SIGUSR2, function (): void {
            $this->restartRequested = true;
        });
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     * @param bool                  $logged a renderer's: its output is read
     *        here and written to the application's log too, when there is one
     * @return resource
     */
    private function spawn(array $command, string $cwd, array $env, bool $logged = false)
    {
        // Children write to this process's standard error, so its standard
        // output carries only the URL a caller may be waiting to read.
        //
        // Their standard input is a pipe this process holds the other end
        // of and never writes to. Killed outright -- SIGKILL, a crash -- it
        // forwards no signal, and the children kept serving the port as
        // orphans; but the system closes the pipe whatever killed it, and
        // each child stops when its input ends.
        //
        // And nothing else of this process's: no file, socket or pipe it
        // holds -- the database, the log, the other children's tethers --
        // reaches a child, the renderer least of all (ChildDescriptors).
        $logged = $logged && $this->rendererLog !== null;
        $output = $logged ? ['pipe', 'w'] : STDERR;
        $process = proc_open($command, ChildDescriptors::closing([0 => ['pipe', 'r'], 1 => $output, 2 => $output]), $pipes, $cwd, $env);
        if (!\is_resource($process)) {
            throw new ServeException('Could not start ' . $command[0] . '.');
        }
        $this->tethers[(int) $process] = $pipes[0];
        if ($logged) {
            foreach ([1 => false, 2 => true] as $fd => $error) {
                stream_set_blocking($pipes[$fd], false);
                $this->outputs[(int) $pipes[$fd]] = [$pipes[$fd], $error];
            }
        }

        return $process;
    }

    /**
     * Waits up to $seconds for a renderer to write, and passes on what it
     * wrote: to standard error as it was, and a line at a time to the
     * application's log. Read promptly, since a renderer whose pipe fills
     * waits on its next write. With nothing to read, a plain wait.
     */
    private function pump(float $seconds): void
    {
        $this->forgetClosedOutputs();
        if ($this->outputs === []) {
            usleep((int) ($seconds * 1e6));

            return;
        }

        $read = array_map(static fn (array $output) => $output[0], array_values($this->outputs));
        $write = $except = null;
        // A signal interrupts the wait, which is then simply over.
        $ready = @stream_select($read, $write, $except, 0, (int) ($seconds * 1e6));
        if ($ready === false || $ready === 0) {
            return;
        }

        foreach ($read as $stream) {
            $this->drain($stream);
        }
    }

    /** @param resource $stream */
    private function drain($stream): void
    {
        $id = (int) $stream;
        if (!isset($this->outputs[$id])) {
            return;
        }
        $error = $this->outputs[$id][1];

        // Up to a megabyte a turn, so one renderer cannot keep the others,
        // or the supervision, waiting.
        for ($read = 0; $read < 1_048_576;) {
            $bytes = fread($stream, 65_536);
            if ($bytes === false || $bytes === '') {
                if (feof($stream)) {
                    $this->rendererLog?->close((string) $id, $error);
                    fclose($stream);
                    unset($this->outputs[$id]);
                }

                return;
            }
            $read += \strlen($bytes);
            fwrite(STDERR, $bytes);
            $this->rendererLog?->feed((string) $id, $bytes, $error);
        }
    }

    /**
     * Drops what is no longer a stream: proc_close() closes the pipes of
     * the process it closes, so a renderer stopped -- or one that exited
     * before it was ready -- leaves its output here closed.
     */
    private function forgetClosedOutputs(): void
    {
        foreach ($this->outputs as $id => [$stream, $error]) {
            if (!\is_resource($stream)) {
                $this->rendererLog?->close((string) $id, $error);
                unset($this->outputs[$id]);
            }
        }
    }

    /** What the renderers wrote as they stopped: read to its end, or for a second at most. */
    private function drainAll(): void
    {
        $deadline = microtime(true) + 1;
        while ($this->outputs !== [] && microtime(true) < $deadline) {
            $this->pump(0.05);
        }
        $this->forgetClosedOutputs();
        foreach ($this->outputs as $id => [$stream, $error]) {
            $this->rendererLog?->close((string) $id, $error);
            fclose($stream);
        }
        $this->outputs = [];
    }

    /**
     * @param \Closure(): bool $ready
     * @param resource         $process
     */
    private function waitFor(\Closure $ready, $process, string $name, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($ready()) {
                return;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                throw new ServeException("{$name} exited with status {$status['exitcode']} before it was ready; its output is above.");
            }
            if ($this->stopRequested()) {
                throw new ServeException('Stopped before everything had started.');
            }
            $this->pump(0.05);
        }

        throw new ServeException("{$name} was not ready within {$seconds}s.");
    }

    /**
     * Stops every child, in stopOrder()'s order, and hands back the job a
     * queue worker killed on the way still held.
     *
     * @param array<string, resource> $children
     * @param string|null $app where the console runs, for the hand-back; none, no hand-back
     * @param array<string, string> $environment the background children's
     */
    private function terminate(array $children, ?string $app = null, array $environment = []): void
    {
        $handBack = null;
        foreach (self::stopOrder(array_keys($children), $this->stopBudget) as [$names, $seconds]) {
            $this->stopAll(array_intersect_key($children, array_flip($names)), $seconds);
            if ($app !== null && \in_array('queue:work', $names, true)) {
                $handBack = $this->startHandBack($app, $environment);
            }
        }
        if ($handBack !== null) {
            $this->finishHandBack($handBack);
        }
        $this->drainAll();
    }

    /**
     * The job a worker killed at the stop still held, back on its queue
     * (HeldJob). A job blocked in a read runs no PHP until the read returns:
     * the worker's own stop could not reach it, and killed holding it, it
     * would wait out its reservation. The worker is killed first -- killing
     * its tether left it running, orphaned -- and is gone before its job is
     * handed back, or the job would run twice. Run beside FrankenPHP's stop,
     * which it needs nothing of.
     *
     * @param array<string, string> $environment
     * @return array{resource, float}|null
     */
    private function startHandBack(string $app, array $environment): ?array
    {
        $console = $this->console;
        $file = $this->held;
        if ($file === null || $console === null) {
            return null;
        }
        $held = HeldJob::read($file);
        if ($held === null) {
            return null;
        }

        $pid = $held['pid'];
        if (HeldJob::running($pid) && self::isWorker($pid)) {
            ($this->err)("The queue worker (pid {$pid}) would not stop holding {$held['job']->id}; killing it, and handing the job back.");
            posix_kill($pid, \defined('SIGKILL') ? SIGKILL : 9);
            $deadline = microtime(true) + 2;
            while (HeldJob::running($pid) && microtime(true) < $deadline) {
                usleep(20_000);
            }
        }
        if (HeldJob::running($pid)) {
            ($this->err)("The queue worker (pid {$pid}) is still running; {$held['job']->id} is left to its reservation.");

            return null;
        }

        $process = proc_open(
            [...PhpCommand::prefix(), $console, 'queue:hand-back', $file],
            [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR],
            $pipes,
            $app,
            $environment,
        );

        return \is_resource($process) ? [$process, microtime(true) + self::HAND_BACK_SECONDS] : null;
    }

    /** @param array{resource, float} $handBack */
    private function finishHandBack(array $handBack): void
    {
        [$process, $deadline] = $handBack;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, \defined('SIGKILL') ? SIGKILL : 9);
        }
        proc_close($process);
    }

    /**
     * Whether $pid runs `queue:work`: a pid written by a worker that has
     * since gone may be another process's now, and it is killed only once
     * something says what it runs -- /proc, or `ps` where there is no /proc
     * (macOS). Nothing to say so is a no: the job is left to its
     * reservation rather than some other process killed.
     */
    private static function isWorker(int $pid): bool
    {
        if (is_dir('/proc')) {
            $cmdline = is_readable("/proc/{$pid}/cmdline") ? @file_get_contents("/proc/{$pid}/cmdline") : false;

            return $cmdline !== false && \in_array('queue:work', explode("\0", $cmdline), true);
        }

        $process = @proc_open(['ps', '-o', 'args=', '-p', (string) $pid], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            return false;
        }
        $args = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || !\is_string($args)) {
            return false;
        }

        return \in_array('queue:work', preg_split('/\s+/', trim($args)) ?: [], true);
    }

    /**
     * The order children are stopped in, a group at a time, and how long
     * each group is given before what is left of it is killed.
     *
     * The queue worker and the scheduler first: a job in hand is finished
     * before the worker goes, and while it runs it may still publish to the
     * server's hub, or build links on the address it serves. Then
     * FrankenPHP, which finishes the requests it has. The renderers last:
     * the requests and jobs before them render through them -- a page, an
     * email -- and stopped together, the renderers drained the renders they
     * had and refused the ones the others were still about to ask for.
     *
     * Stopped all at once, with ten seconds for everything, a job that took
     * longer was killed in hand and run again once its reservation ran out.
     * The background group is given the stop budget (StopBudget), the same
     * the worker is told: within it, a job that cannot finish pauses at a
     * checkpoint or is handed back to its queue before the kill.
     *
     * @param list<string> $names
     * @return list<array{list<string>, int}>
     */
    public static function stopOrder(array $names, int $stopBudget = self::BACKGROUND_STOP_SECONDS): array
    {
        $renderers = array_values(array_intersect($names, ['the renderers', 'the dev renderer']));
        $background = array_values(array_intersect($names, self::BACKGROUND));
        $server = array_values(array_diff($names, $renderers, $background));

        return array_values(array_filter(
            [[$background, $stopBudget], [$server, self::STOP_SECONDS], [$renderers, self::STOP_SECONDS]],
            static fn (array $group): bool => $group[0] !== [],
        ));
    }

    /**
     * How long a stop of these children takes at most: each group in
     * stopOrder() is given its seconds in turn.
     *
     * @param list<string> $names
     */
    public static function stopSeconds(array $names, int $stopBudget = self::BACKGROUND_STOP_SECONDS): int
    {
        return array_sum(array_column(self::stopOrder($names, $stopBudget), 1));
    }

    /**
     * SIGTERM to each child still running, then SIGKILL to any that will
     * not go within $seconds.
     *
     * @param array<string, resource> $children
     */
    private function stopAll(array $children, int $seconds): void
    {
        foreach ($children as $process) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, \defined('SIGTERM') ? SIGTERM : 15);
            }
        }

        $deadline = microtime(true) + $seconds;
        foreach ($children as $process) {
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                $this->pump(0.05);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, \defined('SIGKILL') ? SIGKILL : 9);
            }
            if (isset($this->tethers[(int) $process])) {
                fclose($this->tethers[(int) $process]);
                unset($this->tethers[(int) $process]);
            }
            proc_close($process);
        }
    }

    /**
     * Waits for the pool the renderers' own user runs to answer, as a render
     * would find it: the directory held to the checks a render makes, and
     * each renderer to being that user's process (SocketTrust). Returns how
     * many answered. A pool that fails the checks -- or one that never
     * answers -- stops serve before FrankenPHP starts, rather than leaving
     * every render to be refused.
     */
    private function awaitPool(string $pool, int $seconds = 30): int
    {
        $user = RendererUser::fromEnvironment();
        \assert($user !== null);
        $trust = new SocketTrust($user->owners());
        $health = new RendererHealth(timeoutSeconds: 1.0, trust: $trust);
        $sockets = new RendererSockets($pool);

        clearstatcache(true, $pool);
        if (file_exists($pool) && !is_dir($pool)) {
            throw new ServeException("TINVAR_SOCKET names {$pool}, which is not a directory: it names the pool the renderers' user runs.");
        }

        $deadline = microtime(true) + $seconds;
        $why = [];
        do {
            if ($sockets->isPool()) {
                $healthy = 0;
                $why = [];
                try {
                    $trust->assertPool($pool);
                    foreach ($sockets->candidates(true) as $socket) {
                        try {
                            $health->check($socket);
                            $healthy++;
                        } catch (RendererException $e) {
                            // Answered by the wrong user: not a pool still starting.
                            if ($e->failure() === RenderFailure::RendererUntrusted) {
                                throw $e;
                            }
                            $why[] = basename($socket) . ': ' . $e->getMessage();
                        } catch (ProtocolException $e) {
                            $why[] = basename($socket) . ': ' . $e->getMessage();
                        }
                    }
                } catch (RendererException $e) {
                    throw new ServeException("The renderers' pool is refused: {$e->getMessage()}");
                }
                if ($healthy > 0) {
                    return $healthy;
                }
            }
            if ($this->stopRequested()) {
                throw new ServeException('Stopped before everything had started.');
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        throw new ServeException(
            "No renderer answered in {$pool} within {$seconds}s, run as {$user->name}"
            . ($why === [] ? '; is its service started?' : ' (' . implode('; ', $why) . ').'),
        );
    }

    /** How many renderers are listening in a pool's directory. */
    private static function socketsIn(string $directory): int
    {
        return \count(array_filter(scandir($directory) ?: [], static fn (string $name): bool => str_ends_with($name, '.sock')));
    }

    /**
     * Renderers when none are asked for: half the cores, at least one, and
     * never more than there are PHP workers.
     *
     * Not cores minus workers: workers default to one per core, which would
     * leave none, and a worker rendering a document is mostly waiting on the
     * socket -- on ten cores, one renderer kept its thread at 100% while ten
     * workers used half a core between them. Renderers are what document
     * renders need cores for. Measured there with ten workers (with other
     * work on the machine, so the shape and not the figures), 1 -> 2 -> 4
     * renderers took the catalog from about 450 to 640 to 770 documents a
     * second, and 6 and 8 added a few percent each while taking cores from
     * the workers and everything else on the machine (ADR 0028): half is
     * where the curve has flattened. And each worker has at
     * most one render in flight, so a renderer past the worker count could
     * never be busy.
     */
    public static function defaultRenderers(int $cores, int $workers): int
    {
        return max(1, min($workers, intdiv($cores, 2)));
    }

    /** One worker per core, which is what a machine of this size can run at once. */
    public static function cpus(): int
    {
        $count = 0;
        if (\function_exists('shell_exec')) {
            $count = (int) trim((string) shell_exec('(sysctl -n hw.ncpu || nproc) 2>/dev/null'));
        }

        return max(1, min(64, $count > 0 ? $count : 4));
    }
}
