<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Dev\DevToolbar;
use Tinvar\Jobs\StopBudget;
use Tinvar\Support\DevMode;
use Tinvar\Support\PrivateDirectory;

/**
 * `tinvar dev`: the one command that starts and supervises everything an app
 * needs in dev.
 *
 *   tinvar dev [--host=HOST] [--port=PORT] [--app=DIR] [--frankenphp=PATH] [--worker] [--schedule]
 *
 * tinvar-spec.md, "Dev experience": "`dev` supervises PHP, the renderer and
 * Vite's dev server, with HMR passed through PHP so the app runs on one
 * origin", and "Codegen runs on file change, so TypeScript types update when
 * a Data object, query or action changes". And "Dev and test tooling": only
 * `tinvar dev` includes the dev toolbar, bound "to loopback with a per-run
 * token".
 *
 * So it is three things, each the job of something that already does it:
 *
 *   - PHP, the renderer and Vite are `tinvar serve --dev` (ADR 0034), run as
 *     a child with every argument given here: that command already starts
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
     */
    public function __construct(
        private readonly string $console,
        private readonly array $watch = [],
        private readonly ?\Closure $codegen = null,
        ?\Closure $err = null,
        private readonly ?string $workingDirectory = null,
        private readonly float $poll = 0.5,
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
            $this->serveArguments = $plan['serve'];
            $serve = $this->spawn(
                [...$php, __DIR__ . '/../../bin/tethered.php', ...$php, $this->console, ...$plan['serve']],
                [...array_filter(getenv(), \is_string(...)), DevToolbar::ENV => $run],
            );

            return $this->supervise($serve['process'], $command);
        } finally {
            if (isset($serve)) {
                $this->terminate($serve['process'], $serve['tether']);
            }
            PrivateDirectory::remove($run);
        }
    }

    /**
     * Everything decided before anything starts.
     *
     * @param list<string> $arguments
     * @return array{app: string, serve: list<string>, codegen: list<string>|null}
     */
    public function plan(array $arguments): array
    {
        // First: dev never runs where the environment says production.
        $marker = DevMode::productionMarker(array_filter(getenv(), \is_string(...)));
        if ($marker !== null) {
            throw new ServeException(
                "tinvar dev is refused: {$marker}=production. It runs Vite's dev server unsandboxed, shows raw errors "
                . 'and every request\'s props to the browser, and never runs in production. Serve a build there.',
            );
        }

        $app = null;
        $passed = [];
        foreach ($arguments as $argument) {
            if ($argument === '--dev') {
                continue;
            }
            if (preg_match('/^--app=(.+)\z/s', $argument, $m) === 1) {
                $app = $m[1];
                continue;
            }
            if (preg_match('/^--host=(.*)\z/s', $argument, $m) === 1 && !\in_array($m[1], self::LOOPBACK, true)) {
                throw new ServeException("tinvar dev listens on loopback only (127.0.0.1 or ::1); refusing {$m[1]}.");
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

        return [
            'app' => $resolved,
            'serve' => ['serve', '--dev', "--app={$resolved}", ...$passed],
            'codegen' => $this->codegen === null ? null : ($this->codegen)($resolved),
        ];
    }

    /**
     * Waits for a stop signal or for serve to exit, running codegen whenever
     * the watched sources settle after a change.
     *
     * @param resource $serve
     * @param list<string>|null $codegen
     */
    private function supervise($serve, ?array $codegen): int
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
