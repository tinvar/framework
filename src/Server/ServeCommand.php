<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Support\DevMode;
use Tinvar\Support\PrivateDirectory;

/**
 * `tinvar serve`: an app's renderer and FrankenPHP, started together and
 * stopped together.
 *
 *   tinvar serve [--dev] [--host=HOST] [--port=PORT] [--app=DIR] [--workers=N] [--frankenphp=PATH]
 *
 * With --dev, Vite's dev server is the renderer ("Dev mode: Vite's dev server
 * is the renderer, with HMR and no separate build"): no build is needed, the
 * renderer renders the app from source, and FrankenPHP -- in classic mode, so
 * an edit to the PHP is in the next response too -- proxies the dev server's
 * modules and HMR socket under /_vite/ on its own origin, by the framework's
 * resources/Caddyfile.dev. Refused, loudly, wherever the environment says
 * production, and on any address but loopback.
 *
 * The app is a directory with a Caddyfile and a build: the command knows
 * nothing about any particular app. It passes the app what it decided as
 * environment variables its Caddyfile reads -- TINVAR_HOST, TINVAR_PORT,
 * TINVAR_APP_ROOT, TINVAR_WORKERS, and TINVAR_SOCKET, the renderer the PHP
 * workers talk to -- so the Caddyfile says how the app is served and this
 * says where.
 *
 * The renderer's socket goes in a fresh private directory under /tmp: short
 * enough for macOS's 104-byte limit, and one no other user can reach into.
 * The renderer gets a scrubbed environment, as it requires; FrankenPHP gets
 * this process's, since the application reads its settings from it.
 *
 * It prints the URL once FrankenPHP is answering on it, forwards SIGINT and
 * SIGTERM to both children, and if either exits on its own stops the other
 * and exits non-zero. Killed outright, it can do none of that, so each child
 * also stops when this process's end of its standard input closes; and the
 * next start removes the directory a killed one left.
 */
final class ServeCommand
{
    public const string DEFAULT_HOST = '127.0.0.1';

    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    private const array OPTIONS = ['host', 'port', 'app', 'workers', 'frankenphp'];

    /** Options that take no value. */
    private const array FLAGS = ['dev'];

    /**
     * What only `serve --dev` hands FrankenPHP: kept out of the environment of
     * every serve that is not, so none left over in a shell or an env file
     * turns dev mode on, or points a proxy at whatever holds the port.
     */
    private const array DEV_ENV = ['TINVAR_DEV', 'TINVAR_VITE_PORT', 'TINVAR_DEFERRED_MANIFEST'];

    /** The addresses dev mode may listen on. */
    private const array LOOPBACK = ['127.0.0.1', '::1'];

    /** Variables the renderer may see; it refuses to start holding anything secret. */
    private const array RENDERER_ENV = ['PATH', 'TZ', 'LANG', 'LC_ALL'];

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    private bool $stopping = false;

    /**
     * The write end of each child's standard input, by process: held open
     * and never written to. See spawn().
     *
     * @var array<int, resource>
     */
    private array $tethers = [];

    /**
     * @param string $projectRoot where the framework's node_modules and tools are
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     * @param (\Closure(): array<string, string>)|null $serverEnvironment more of
     *        FrankenPHP's environment, from the application -- its live hub's
     *        keys, which its Caddyfile reads (Tinvar\Live\HubSettings). Asked
     *        once FrankenPHP is about to start, and never given to the
     *        renderer, whose environment stays scrubbed.
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly PortFinder $ports = new PortFinder(),
        ?\Closure $out = null,
        ?\Closure $err = null,
        private readonly ?string $workingDirectory = null,
        private readonly ?\Closure $serverEnvironment = null,
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
     * @return array{host: string, port: int, app: string, entry: string|null, renderer: string, frankenphp: string, workers: int, dev: bool, vitePort: int|null, caddyfile: string}
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

        $host = $options['host'] ?? self::DEFAULT_HOST;
        PortFinder::assertHost($host);
        if ($dev && !\in_array($host, self::LOOPBACK, true)) {
            throw new ServeException("tinvar serve --dev listens on loopback only (127.0.0.1 or ::1); refusing {$host}.");
        }

        $app = $this->app($options['app'] ?? null, requireCaddyfile: !$dev);
        $entry = $dev ? null : "{$app}/dist/rsc/index.js";
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

        $frankenphp = FrankenPhp::locate($this->projectRoot, $options['frankenphp'] ?? null);
        $renderer = $this->rendererBin($dev ? 'dev-renderer.js' : 'renderer.js');

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
            'workers' => $workers === null ? self::cpus() : (int) $workers,
            'port' => $port,
            'dev' => $dev,
            'vitePort' => $vitePort,
            'caddyfile' => $dev ? \dirname(__DIR__, 2) . '/resources/Caddyfile.dev' : "{$app}/Caddyfile",
        ];
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string>
     */
    private static function parse(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z]+)\z/', $argument, $m) === 1 && \in_array($m[1], self::FLAGS, true)) {
                $options[$m[1]] = '';
                continue;
            }
            if (preg_match('/^--([a-z]+)=(.*)\z/s', $argument, $m) !== 1 || !\in_array($m[1], self::OPTIONS, true)) {
                throw new ServeException(
                    "Unknown argument '{$argument}'. Usage: tinvar serve [--dev] [--host=HOST] [--port=PORT] [--app=DIR] "
                    . '[--workers=N] [--frankenphp=PATH]',
                );
            }
            $options[$m[1]] = $m[2];
        }

        return $options;
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
        foreach ([
            "{$this->projectRoot}/node_modules/@tinvar/renderer/bin/{$bin}",
            "{$this->projectRoot}/packages/renderer/bin/{$bin}",
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new ServeException('The renderer is not installed: run npm ci in ' . $this->projectRoot . '.');
    }

    /** @param array{host: string, port: int, app: string, entry: string|null, renderer: string, frankenphp: string, workers: int, dev: bool, vitePort: int|null, caddyfile: string} $plan */
    private function serve(array $plan): int
    {
        // One killed outright had no chance to remove its own.
        PrivateDirectory::removeAbandoned('tinvar-serve');
        $directory = PrivateDirectory::create('tinvar-serve', owner: getmypid() ?: null);
        $socket = "{$directory}/renderer.sock";
        $deferred = "{$directory}/deferred.json";
        $children = [];

        $this->trapSignals();

        $rendererEnv = array_intersect_key(getenv(), array_flip(self::RENDERER_ENV));
        // What FrankenPHP is told on top of this process's environment and the
        // application's (serverEnvironment, asked as FrankenPHP starts).
        $phpEnv = [
            'TINVAR_HOST' => $plan['host'],
            'TINVAR_PORT' => (string) $plan['port'],
            'TINVAR_APP_ROOT' => $plan['app'],
            'TINVAR_WORKERS' => (string) $plan['workers'],
            'TINVAR_SOCKET' => $socket,
        ];

        try {
            if ($plan['dev']) {
                // The dev server keeps the views' <Deferred> components up to
                // date beside the socket, as a build writes them, so a route
                // mints in dev exactly when its build would let it.
                $children['the dev renderer'] = $renderer = $this->spawn(
                    ['node', $plan['renderer'], $socket, $plan['app'], "--port={$plan['vitePort']}", "--deferred={$deferred}", '--exit-with-stdin'],
                    $plan['app'],
                    [...$rendererEnv, 'NODE_ENV' => 'development'],
                );
                // Longer than a built renderer's start: Vite optimizes the
                // app's dependencies the first time.
                $this->waitFor(static fn (): bool => file_exists($socket), $renderer, 'The dev renderer', 90);
                $phpEnv = [
                    ...$phpEnv,
                    'TINVAR_DEV' => '1',
                    'TINVAR_VITE_PORT' => (string) $plan['vitePort'],
                    'TINVAR_DEFERRED_MANIFEST' => $deferred,
                ];
            } else {
                $children['the renderer'] = $renderer = $this->spawn(
                    ['node', $plan['renderer'], $socket, (string) $plan['entry'], '--exit-with-stdin'],
                    $plan['app'],
                    [...$rendererEnv, 'NODE_ENV' => 'production'],
                );
                $this->waitFor(static fn (): bool => file_exists($socket), $renderer, 'The renderer', 20);
            }

            // FrankenPHP watches nothing of its parent's, so it runs under a
            // PHP process that watches standard input for it.
            $children['FrankenPHP'] = $server = $this->spawn(
                [
                    PHP_BINARY,
                    __DIR__ . '/../../bin/tethered.php',
                    $plan['frankenphp'],
                    'run',
                    '--config',
                    $plan['caddyfile'],
                    '--adapter',
                    'caddyfile',
                ],
                $plan['app'],
                [
                    ...array_diff_key(getenv(), array_flip(self::DEV_ENV)),
                    ...($this->serverEnvironment === null ? [] : ($this->serverEnvironment)()),
                    ...$phpEnv,
                ],
            );
            $this->waitFor(static fn (): bool => PortFinder::answers($plan['host'], $plan['port']), $server, 'FrankenPHP', 30);
        } catch (ServeException $e) {
            ($this->err)($e->getMessage());
            $this->terminate($children);
            PrivateDirectory::remove($directory);

            return 1;
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
                'Serving %s with %d FrankenPHP workers and a renderer at %s. Ctrl+C stops both.',
                $plan['app'],
                $plan['workers'],
                $socket,
            ));

        $status = $this->supervise($children);
        $this->terminate($children);
        PrivateDirectory::remove($directory);

        return $status;
    }

    /**
     * Waits for a stop signal or for a child to exit on its own.
     *
     * @param array<string, resource> $children
     */
    private function supervise(array $children): int
    {
        for (;;) {
            if ($this->stopRequested()) {
                return 0;
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

                ($this->err)("{$name} exited with status {$status['exitcode']}; stopping the rest.");

                return 1;
            }

            usleep(100_000);
        }
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
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     * @return resource
     */
    private function spawn(array $command, string $cwd, array $env)
    {
        // Children write to this process's standard error, so its standard
        // output carries only the URL a caller may be waiting to read.
        //
        // Their standard input is a pipe this process holds the other end
        // of and never writes to. Killed outright -- SIGKILL, a crash -- it
        // forwards no signal, and the children kept serving the port as
        // orphans; but the system closes the pipe whatever killed it, and
        // each child stops when its input ends.
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDERR, 2 => STDERR], $pipes, $cwd, $env);
        if (!\is_resource($process)) {
            throw new ServeException('Could not start ' . $command[0] . '.');
        }
        $this->tethers[(int) $process] = $pipes[0];

        return $process;
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
            usleep(50_000);
        }

        throw new ServeException("{$name} was not ready within {$seconds}s.");
    }

    /**
     * SIGTERM to each child still running, then SIGKILL to any that will not go.
     *
     * @param array<string, resource> $children
     */
    private function terminate(array $children): void
    {
        foreach ($children as $process) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, \defined('SIGTERM') ? SIGTERM : 15);
            }
        }

        $deadline = microtime(true) + 10;
        foreach ($children as $process) {
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(50_000);
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

    /** One worker per core, which is what a machine of this size can run at once. */
    private static function cpus(): int
    {
        $count = 0;
        if (\function_exists('shell_exec')) {
            $count = (int) trim((string) shell_exec('(sysctl -n hw.ncpu || nproc) 2>/dev/null'));
        }

        return max(1, min(64, $count > 0 ? $count : 4));
    }
}
