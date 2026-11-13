<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Dev\DevToolbar;
use Tinvar\Jobs\StopBudget;
use Tinvar\Support\DevMode;
use Tinvar\Support\PrivateDirectory;

/**
 * `tinvar serve --dev`: the one command that starts and supervises
 * everything an app needs in dev (`tinvar dev` before ADR 0199).
 *
 *   tinvar serve --dev [--host=HOST] [--port=PORT] [--app=DIR] [--frankenphp=PATH] [--worker] [--schedule]
 *                      [--tunnel | --public-host=NAME[,NAME...] [--share]]
 *
 * tinvar-spec.md, "Dev experience": "`dev` supervises PHP, the renderer and
 * Vite's dev server, with HMR passed through PHP so the app runs on one
 * origin", and "Codegen runs on file change, so TypeScript types update when
 * a Data object, query or action changes". And "Dev and test tooling": only
 * `tinvar serve --dev` includes the dev toolbar, bound "to loopback with a per-run
 * token".
 *
 * So it is three things, each the job of something that already does it:
 *
 *   - PHP, the renderer and Vite are the console's `serve:process --dev`
 *     (ADR 0034; `serve --dev` itself before ADR 0199), run as a child with
 *     every argument given here: that command already starts
 *     FrankenPHP in front of Vite's dev server on one origin, refuses
 *     production and anything but loopback, and stops everything together.
 *   - Codegen is the application's own command, run afresh in a process of
 *     its own whenever the PHP under the watched directories settles after a
 *     change (SourceWatcher), and once at the start -- PHP cannot load a
 *     changed class twice, so codegen can never run in this process.
 *   - The toolbar's run: a private directory holding a fresh key, named to
 *     FrankenPHP in TINVAR_DEV_TOOLBAR, where the application's DevToolbar
 *     keeps what it records. The renderer never sees it: serve hands the
 *     renderer a scrubbed environment of its own. Removed when this stops,
 *     so the key dies with the run.
 *
 * With --tunnel it also opens a Cloudflare quick tunnel to it: cloudflared,
 * run here, which prints the trycloudflare.com name it was given, and serve
 * is told that name as --public-host with --share, to let in a visitor
 * holding the share token it prints and no one else (ADR 0190). A quick
 * tunnel is always shared: its address is all that stands in front of the
 * app's source. --public-host names the hosts of a tunnel
 * the developer runs themselves, a named one on a domain of theirs.
 *
 * Refused first, before anything is read or started, wherever the
 * environment says production; and on any host but loopback. It forwards
 * SIGINT and SIGTERM to serve, and exits as serve did when serve exits on
 * its own. Killed outright it forwards nothing, so serve runs under the same
 * tether its own children do, and stops when this process's end of the pipe
 * closes.
 */
final class DevCommand
{
    public const int USAGE = 64;

    /** Seconds codegen may take before it is stopped; it reads a few hundred classes. */
    public const int CODEGEN_TIMEOUT = 60;

    /** The addresses dev may listen on, as serve --dev has them. */
    private const array LOOPBACK = ['127.0.0.1', '::1'];

    /** Where a cloudflared other than the PATH's is named. */
    public const string CLOUDFLARED_ENV = 'TINVAR_CLOUDFLARED';

    /** Seconds cloudflared may take to say the name its quick tunnel was given. */
    public const int TUNNEL_TIMEOUT = 30;

    /**
     * The name a quick tunnel is given, as cloudflared prints it: alone in
     * its banner's box. Anywhere else -- the API it asks, api.trycloudflare.com,
     * named in an error -- it is no tunnel's.
     */
    private const string QUICK_TUNNEL = '~\|\s+https://((?!api\.)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.trycloudflare\.com)\s+\|~';

    /** What cloudflared is given of dev's environment: nothing of the app's, its keys and credentials. */
    private const array CLOUDFLARED_ENV_KEPT = ['PATH', 'HOME', 'TMPDIR', 'USER', 'LANG'];

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    private bool $stopping = false;

    /** @var list<string> what serve was run with, which says how long it takes to stop */
    private array $serveArguments = [];

    /**
     * @param string $console the CLI whose `serve` runs PHP, the renderer and Vite
     * @param list<string> $watch the directories (and files) of the application's PHP that codegen
     *        reads: where its composer.json autoloads it from (ComposerSources)
     * @param (\Closure(string): list<string>)|null $codegen the codegen command for the
     *        app directory given; without one, nothing is generated
     * @param (\Closure(string): void)|null $err a line for standard error; standard output
     *        is serve's, whose URL is the line a caller waits for
     * @param float $poll seconds between looks at the watched sources
     * @param string $serve the console's command that runs FrankenPHP and the renderer alone:
     *        `serve:process` under an AppConsole, whose `serve --dev` is this (ADR 0199)
     */
    public function __construct(
        private readonly string $console,
        private readonly array $watch = [],
        private readonly ?\Closure $codegen = null,
        ?\Closure $err = null,
        private readonly ?string $workingDirectory = null,
        private readonly float $poll = 0.5,
        private readonly PortFinder $ports = new PortFinder(),
        private readonly string $serve = 'serve',
    ) {
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

        // One killed outright had no chance to remove its own, key and all.
        PrivateDirectory::removeAbandoned('tinvar-dev');
        $run = PrivateDirectory::create('tinvar-dev', owner: getmypid() ?: null);

        try {
            DevToolbar::prepare($run);
            $this->trapSignals();

            $command = $plan['codegen'];
            if ($command !== null) {
                $this->generate($command, []);
            }

            $php = PhpCommand::prefix();
            $serveArguments = $plan['serve'];
            if ($plan['cloudflared'] !== null) {
                [$tunnel, $serveArguments] = $this->openTunnel($plan['cloudflared'], $serveArguments, $run);
            }
            $this->serveArguments = $serveArguments;
            $serve = $this->spawn(
                [...$php, __DIR__ . '/../../bin/tethered.php', ...$php, $this->console, ...$serveArguments],
                [...array_filter(getenv(), \is_string(...)), DevToolbar::ENV => $run],
            );

            return $this->supervise($serve['process'], $command, $tunnel ?? null);
        } catch (ServeException $e) {
            ($this->err)($e->getMessage());

            // Stopped while the tunnel opened is a stop, as one after is.
            return $this->stopping ? 0 : 1;
        } finally {
            if (isset($serve)) {
                $this->terminate($serve['process'], $serve['tether']);
            }
            if (isset($tunnel)) {
                $this->closeTunnel($tunnel);
            }
            PrivateDirectory::remove($run);
        }
    }

    /**
     * Everything decided before anything starts.
     *
     * @param list<string> $arguments
     * @return array{app: string, serve: list<string>, codegen: list<string>|null, cloudflared: string|null}
     */
    public function plan(array $arguments): array
    {
        // First: dev runs only where the environment says it is a
        // developer's machine (ADR 0213), never where it says nothing.
        $why = DevMode::notDevelopment();
        if ($why !== null) {
            throw new ServeException(
                "tinvar serve --dev is refused: {$why}. It runs Vite's dev server unsandboxed, shows raw errors "
                . 'and every request\'s props to the browser, and runs only on a developer\'s machine: set TINVAR_ENV=local '
                . 'there (`tinvar setup` writes it into .env). Serve a build anywhere else.',
            );
        }

        $app = null;
        $passed = [];
        $tunnel = false;
        $publicHost = false;
        foreach ($arguments as $argument) {
            if ($argument === '--dev') {
                continue;
            }
            if ($argument === '--tunnel') {
                $tunnel = true;
                continue;
            }
            if (str_starts_with($argument, '--public-host=')) {
                $publicHost = true;
            }
            if (preg_match('/^--app=(.+)\z/s', $argument, $m) === 1) {
                $app = $m[1];
                continue;
            }
            if (preg_match('/^--host=(.*)\z/s', $argument, $m) === 1 && !\in_array($m[1], self::LOOPBACK, true)) {
                throw new ServeException("tinvar serve --dev listens on loopback only (127.0.0.1 or ::1); refusing {$m[1]}.");
            }
            // The rest is serve's to read, and to refuse.
            $passed[] = $argument;
        }

        $cwd = $this->workingDirectory ?? (string) getcwd();
        $candidate = $app === null ? $cwd : (str_starts_with($app, '/') ? $app : "{$cwd}/{$app}");
        $resolved = realpath($candidate);
        if ($resolved === false || !is_dir($resolved)) {
            throw new ServeException("No app at {$candidate}.");
        }
        if (!is_file("{$resolved}/public/index.php")) {
            throw new ServeException("{$resolved} has no public/index.php, which dev runs for every request.");
        }

        if ($tunnel && $publicHost) {
            throw new ServeException('--tunnel opens a quick tunnel and names it to serve itself; --public-host names one you run. Pass one of them.');
        }

        return [
            'app' => $resolved,
            'serve' => [$this->serve, '--dev', "--app={$resolved}", ...$passed],
            'codegen' => $this->codegen === null ? null : ($this->codegen)($resolved),
            // Looked for now, so a missing one stops dev before anything starts.
            'cloudflared' => $tunnel ? self::cloudflared() : null,
        ];
    }

    /**
     * Waits for a stop signal or for serve to exit, running codegen whenever
     * the watched sources settle after a change.
     *
     * @param resource $serve
     * @param list<string>|null $codegen
     * @param array{process: resource, tether: resource, log: string}|null $tunnel
     */
    private function supervise($serve, ?array $codegen, ?array $tunnel = null): int
    {
        $watcher = $codegen === null || $this->watch === [] ? null : new SourceWatcher($this->watch);

        for (;;) {
            if ($this->stopRequested()) {
                return 0;
            }

            $status = proc_get_status($serve);
            if (!$status['running']) {
                // A Ctrl+C reaches serve too, which may exit before the
                // signal reaches this loop: a stop, not a crash.
                usleep(50_000);
                if ($this->stopRequested()) {
                    return 0;
                }
                ($this->err)("tinvar serve exited with status {$status['exitcode']}; stopping.");

                return $status['exitcode'] === 0 ? 1 : $status['exitcode'];
            }

            // The tunnel gone, the address dev printed leads nowhere: said,
            // and everything stopped, rather than serving on as if shared.
            if ($tunnel !== null && !proc_get_status($tunnel['process'])['running']) {
                if ($this->stopRequested()) {
                    return 0;
                }
                ($this->err)('cloudflared exited, so the tunnel is closed; stopping. Its last lines:');
                foreach (self::lastLines($tunnel['log']) as $line) {
                    ($this->err)("[cloudflared]   {$line}");
                }

                return 1;
            }

            $changed = $watcher?->changes() ?? [];
            if ($codegen !== null && $changed !== []) {
                $this->generate($codegen, $changed);
            }

            usleep((int) ($this->poll * 1e6));
        }
    }

    /**
     * Runs codegen and says what it changed. A failure -- a syntax error in
     * the class being edited, most often -- is reported and the types are
     * left as they were; the next save runs it again.
     *
     * @param list<string> $command
     * @param list<string> $changed the sources that changed, to name in the report
     */
    private function generate(array $command, array $changed): void
    {
        $because = $changed === [] ? 'at start' : 'after ' . self::named($changed);
        [$status, $output] = self::capture($command, $this->workingDirectory ?? (string) getcwd(), self::CODEGEN_TIMEOUT);

        if ($status !== 0) {
            ($this->err)("[codegen] failed {$because} (status {$status}); the types are as they were:");
            foreach (\array_slice(explode("\n", trim($output)), -20) as $line) {
                ($this->err)("[codegen]   {$line}");
            }

            return;
        }

        $written = array_values(array_filter(
            explode("\n", trim($output)),
            static fn (string $line): bool => str_starts_with($line, 'wrote ') || str_starts_with($line, 'removed '),
        ));
        ($this->err)($written === []
            ? "[codegen] types up to date {$because}"
            : "[codegen] {$because}: " . implode('; ', $written));
    }

    /** @param list<string> $paths */
    private static function named(array $paths): string
    {
        $names = array_map(basename(...), \array_slice($paths, 0, 3));

        return implode(', ', $names) . (\count($paths) > 3 ? ' and ' . (\count($paths) - 3) . ' more' : '');
    }

    /**
     * A command's exit status and output, stopped past $seconds.
     *
     * @param list<string> $command
     * @return array{int, string}
     */
    private static function capture(array $command, string $cwd, int $seconds): array
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!\is_resource($process)) {
            return [1, "Could not start {$command[0]}."];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Both read as they fill, so neither pipe's buffer stops the command.
        $output = '';
        $deadline = microtime(true) + $seconds;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if ((int) @stream_select($read, $write, $except, 0, 100_000) > 0) {
                foreach ($read as $pipe) {
                    $output .= (string) fread($pipe, 65536);
                }
            }
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if ($status['running']) {
            proc_terminate($process, \defined('SIGKILL') ? SIGKILL : 9);
            proc_close($process);

            return [124, $output . "\nStopped after {$seconds}s."];
        }
        proc_close($process);

        return [$status['exitcode'], $output];
    }

    /**
     * cloudflared: the one TINVAR_CLOUDFLARED names, else the PATH's.
     */
    private static function cloudflared(): string
    {
        $named = getenv(self::CLOUDFLARED_ENV);
        if (\is_string($named) && $named !== '') {
            if (!str_starts_with($named, '/') || !is_file($named) || !is_executable($named)) {
                throw new ServeException(self::CLOUDFLARED_ENV . " names {$named}, which is not an executable file at an absolute path.");
            }

            return $named;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_file("{$directory}/cloudflared") && is_executable("{$directory}/cloudflared")) {
                return "{$directory}/cloudflared";
            }
        }

        throw new ServeException(
            '--tunnel runs cloudflared, which is not on the PATH. Install it (brew install cloudflared, or see '
            . 'https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/), '
            . 'or name it in ' . self::CLOUDFLARED_ENV . '.',
        );
    }

    /**
     * A quick tunnel to the port serve is to listen on, and serve's
     * arguments with that port and the tunnel's name in them.
     *
     * The port is chosen here, as serve would choose it, since cloudflared
     * is told it before serve starts: the tunnel's name is known only once
     * cloudflared has it, and serve needs the name to start.
     *
     * @param list<string> $serveArguments
     * @return array{array{process: resource, tether: resource, log: string}, list<string>}
     */
    private function openTunnel(string $cloudflared, array $serveArguments, string $run): array
    {
        $host = ServeCommand::DEFAULT_HOST;
        $port = null;
        $rest = [];
        foreach ($serveArguments as $argument) {
            if (preg_match('/^--host=(.*)\z/s', $argument, $m) === 1) {
                $host = $m[1];
            } elseif (preg_match('/^--port=(.*)\z/s', $argument, $m) === 1) {
                $port = $m[1];
                continue;
            }
            $rest[] = $argument;
        }
        $port = $this->ports->find($host, $port);

        // Its output to a file of the run's, read for the name and, if it
        // exits, for why: a pipe nobody drained would stop it.
        $log = "{$run}/cloudflared.log";
        $process = proc_open(
            [
                // Killed if it outlasts its own grace: a stop is not left
                // waiting on the HMR socket and the hub's stream, which a
                // tunnel always has open.
                ...PhpCommand::prefix(), __DIR__ . '/../../bin/tethered.php', '--kill-after=5',
                $cloudflared, 'tunnel', '--no-autoupdate', '--grace-period', '1s',
                '--url', 'http://' . PortFinder::hostForUrl($host) . ":{$port}",
            ],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $this->workingDirectory,
            // A third party's binary, facing the network: none of the app's
            // keys or credentials dev's own environment may carry.
            array_intersect_key(array_filter(getenv(), \is_string(...)), array_flip(self::CLOUDFLARED_ENV_KEPT)),
        );
        if (!\is_resource($process)) {
            throw new ServeException("Could not start {$cloudflared}.");
        }
        $tunnel = ['process' => $process, 'tether' => $pipes[0], 'log' => $log];

        ($this->err)('[tunnel] opening a Cloudflare quick tunnel...');
        $deadline = microtime(true) + self::TUNNEL_TIMEOUT;
        while (microtime(true) < $deadline) {
            if (preg_match(self::QUICK_TUNNEL, (string) @file_get_contents($log), $m) === 1) {
                ($this->err)("[tunnel] {$m[1]} reaches port {$port}; serve answers it with the share token alone.");

                // Shared, always: the random name is all that stands in
                // front of the app's source otherwise.
                return [$tunnel, [...$rest, "--port={$port}", "--public-host={$m[1]}", '--share']];
            }
            if (!proc_get_status($process)['running'] || $this->stopRequested()) {
                break;
            }
            usleep(100_000);
        }

        $lines = self::lastLines($log);
        $this->closeTunnel($tunnel);
        if ($this->stopping) {
            throw new ServeException('Stopped before the tunnel opened.');
        }

        throw new ServeException(
            'cloudflared gave the tunnel no trycloudflare.com name within ' . self::TUNNEL_TIMEOUT . " s. Its last lines:\n  "
            . implode("\n  ", $lines),
        );
    }

    /** @param array{process: resource, tether: resource, log: string} $tunnel */
    private function closeTunnel(array $tunnel): void
    {
        if (proc_get_status($tunnel['process'])['running']) {
            proc_terminate($tunnel['process'], \defined('SIGTERM') ? SIGTERM : 15);
        }
        $deadline = microtime(true) + 10;
        while (proc_get_status($tunnel['process'])['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($tunnel['process'])['running']) {
            proc_terminate($tunnel['process'], \defined('SIGKILL') ? SIGKILL : 9);
        }
        fclose($tunnel['tether']);
        proc_close($tunnel['process']);
    }

    /** @return list<string> the last lines of a log, for saying why something stopped */
    private static function lastLines(string $log, int $count = 10): array
    {
        $lines = array_values(array_filter(explode("\n", trim((string) @file_get_contents($log))), static fn (string $l): bool => $l !== ''));

        return \array_slice($lines, -$count);
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
     * Serve, with standard output and error this process's own -- the URL it
     * prints is the line a caller waits for -- and standard input a pipe this
     * process holds and never writes to, which the tether watches.
     *
     * @param list<string>          $command
     * @param array<string, string> $env
     * @return array{process: resource, tether: resource}
     */
    private function spawn(array $command, array $env): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $this->workingDirectory, $env);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start tinvar serve.');
        }

        return ['process' => $process, 'tether' => $pipes[0]];
    }

    /**
     * How long serve is given to stop: every group of children it stops in
     * turn, each given what serve gives it (ServeCommand::stopOrder()) --
     * the queue worker and scheduler the stop budget, a job in hand among
     * them -- and a few seconds over for serve itself.
     *
     * A fixed fifteen seconds was shorter than serve's own stop: ten for
     * FrankenPHP and ten for the renderer, and with --worker the minute and
     * more a running job is given. Killed first, serve could stop none of
     * it, and a job in hand was killed rather than handed back.
     *
     * @param list<string> $serveArguments what serve is run with
     */
    public static function stopSeconds(array $serveArguments, int $stopBudget): int
    {
        $children = ['the dev renderer', 'FrankenPHP'];
        if (\in_array('--worker', $serveArguments, true)) {
            $children[] = 'queue:work';
        }
        if (\in_array('--schedule', $serveArguments, true)) {
            $children[] = 'schedule:work';
        }

        return ServeCommand::stopSeconds($children, $stopBudget) + self::STOP_MARGIN_SECONDS;
    }

    /** What serve is given to stop beyond its children's time: its own exit. */
    private const int STOP_MARGIN_SECONDS = 5;

    /**
     * SIGTERM to serve, which stops what it started, and SIGKILL if it has
     * not gone within serve's own stop (stopSeconds()).
     *
     * @param resource $process
     * @param resource $tether
     */
    private function terminate($process, $tether): void
    {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, \defined('SIGTERM') ? SIGTERM : 15);
        }

        try {
            $budget = StopBudget::fromEnvironment();
        } catch (\InvalidArgumentException) {
            // serve refuses one that is not, and stops nothing then.
            $budget = StopBudget::DEFAULT;
        }
        $deadline = microtime(true) + self::stopSeconds($this->serveArguments, $budget);
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, \defined('SIGKILL') ? SIGKILL : 9);
        }
        fclose($tether);
        proc_close($process);
    }
}
