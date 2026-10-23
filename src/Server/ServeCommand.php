<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Support\PrivateDirectory;

/**
 * `tinvar serve`: an app's renderer and FrankenPHP, started together and
 * stopped together.
 *
 *   tinvar serve [--host=HOST] [--port=PORT] [--app=DIR] [--workers=N] [--frankenphp=PATH]
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
 * and exits non-zero.
 */
final class ServeCommand
{
    public const string DEFAULT_HOST = '127.0.0.1';

    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    private const array OPTIONS = ['host', 'port', 'app', 'workers', 'frankenphp'];

    /** Variables the renderer may see; it refuses to start holding anything secret. */
    private const array RENDERER_ENV = ['PATH', 'TZ', 'LANG', 'LC_ALL'];

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    private bool $stopping = false;

    /**
     * @param string $projectRoot where the framework's node_modules and tools are
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly PortFinder $ports = new PortFinder(),
        ?\Closure $out = null,
        ?\Closure $err = null,
        private readonly ?string $workingDirectory = null,
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
     * @return array{host: string, port: int, app: string, entry: string, renderer: string, frankenphp: string, workers: int}
     */
    public function plan(array $arguments): array
    {
        $options = self::parse($arguments);

        $host = $options['host'] ?? self::DEFAULT_HOST;
        PortFinder::assertHost($host);

        $app = $this->app($options['app'] ?? null);
        $entry = "{$app}/dist/rsc/index.js";
        if (!is_file($entry)) {
            throw new ServeException("{$app} is not built: there is no {$entry}. Build it first (npx vite build in {$app}).");
        }

        $workers = $options['workers'] ?? null;
        if ($workers !== null && (preg_match('/^[1-9][0-9]{0,2}\z/', $workers) !== 1 || (int) $workers > 256)) {
            throw new ServeException("--workers must be a whole number from 1 to 256; got '{$workers}'.");
        }

        return [
            'host' => $host,
            'frankenphp' => FrankenPhp::locate($this->projectRoot, $options['frankenphp'] ?? null),
            'app' => $app,
            'entry' => $entry,
            'renderer' => $this->rendererBin(),
            'workers' => $workers === null ? self::cpus() : (int) $workers,
            // Last: choosing a port binds it for a moment, which is only
            // worth doing once everything else is known to be there.
            'port' => $this->ports->find($host, $options['port'] ?? null),
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
            if (preg_match('/^--([a-z]+)=(.*)\z/s', $argument, $m) !== 1 || !\in_array($m[1], self::OPTIONS, true)) {
                throw new ServeException(
                    "Unknown argument '{$argument}'. Usage: tinvar serve [--host=HOST] [--port=PORT] [--app=DIR] "
                    . '[--workers=N] [--frankenphp=PATH]',
                );
            }
            $options[$m[1]] = $m[2];
        }

        return $options;
    }

    /** The app's directory: --app, or the working directory if it has a Caddyfile. */
    private function app(?string $given): string
    {
        $cwd = $this->workingDirectory ?? (string) getcwd();
        $candidate = $given === null ? $cwd : (str_starts_with($given, '/') ? $given : "{$cwd}/{$given}");
        $app = realpath($candidate);

        if ($app === false || !is_dir($app)) {
            throw new ServeException("No app at {$candidate}.");
        }
        if (!is_file("{$app}/Caddyfile")) {
            throw new ServeException(
                $given === null
                    ? 'No app to serve here: run from an app directory with a Caddyfile, or pass --app=DIR.'
                    : "{$app} has no Caddyfile, so there is nothing to tell FrankenPHP how to serve it.",
            );
        }

        return $app;
    }

    private function rendererBin(): string
    {
        foreach ([
            "{$this->projectRoot}/node_modules/@tinvar/renderer/bin/renderer.js",
            "{$this->projectRoot}/packages/renderer/bin/renderer.js",
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new ServeException('The renderer is not installed: run npm ci in ' . $this->projectRoot . '.');
    }

    /** @param array{host: string, port: int, app: string, entry: string, renderer: string, frankenphp: string, workers: int} $plan */
    private function serve(array $plan): int
    {
        $directory = PrivateDirectory::create('tinvar-serve');
        $socket = "{$directory}/renderer.sock";
        $children = [];

        $this->trapSignals();

        try {
            $children['the renderer'] = $renderer = $this->spawn(
                ['node', $plan['renderer'], $socket, $plan['entry']],
                $plan['app'],
                [...array_intersect_key(getenv(), array_flip(self::RENDERER_ENV)), 'NODE_ENV' => 'production'],
            );
            $this->waitFor(static fn (): bool => file_exists($socket), $renderer, 'The renderer', 20);

            $children['FrankenPHP'] = $server = $this->spawn(
                [$plan['frankenphp'], 'run', '--config', "{$plan['app']}/Caddyfile", '--adapter', 'caddyfile'],
                $plan['app'],
                [
                    ...getenv(),
                    'TINVAR_HOST' => $plan['host'],
                    'TINVAR_PORT' => (string) $plan['port'],
                    'TINVAR_APP_ROOT' => $plan['app'],
                    'TINVAR_WORKERS' => (string) $plan['workers'],
                    'TINVAR_SOCKET' => $socket,
                ],
            );
            $this->waitFor(static fn (): bool => self::answers($plan['host'], $plan['port']), $server, 'FrankenPHP', 30);
        } catch (ServeException $e) {
            ($this->err)($e->getMessage());
            $this->terminate($children);
            PrivateDirectory::remove($directory);

            return 1;
        }

        ($this->out)(sprintf('Listening on http://%s:%d', PortFinder::hostForUrl($plan['host']), $plan['port']));
        ($this->err)(sprintf(
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
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR], $pipes, $cwd, $env);
        if (!\is_resource($process)) {
            throw new ServeException('Could not start ' . $command[0] . '.');
        }

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
            proc_close($process);
        }
    }

    /** Whether something accepts a connection on host and port. */
    private static function answers(string $host, int $port): bool
    {
        // Listening on every interface is reached through loopback.
        $connect = match ($host) {
            '0.0.0.0' => '127.0.0.1',
            '::' => '::1',
            default => $host,
        };

        set_error_handler(static fn (): bool => true);
        try {
            $socket = fsockopen(PortFinder::hostForUrl($connect), $port, $errno, $error, 0.2);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
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
