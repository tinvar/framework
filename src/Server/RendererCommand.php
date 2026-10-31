<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Render\RendererException;
use Tinvar\Render\RendererHealth;
use Tinvar\Render\RendererUser;
use Tinvar\Render\SocketTrust;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\ChildDescriptors;
use Tinvar\Support\PrivateDirectory;

/**
 * The spec's renderer lifecycle commands, for a pool run on its own or under
 * `tinvar serve`:
 *
 *   tinvar renderer:start [--processes=N] [--app=DIR] [--socket=DIR] [--codec=msgpack|json-lines] [--socket-group=GROUP]
 *   tinvar renderer:stop [--graceful] [--socket=DIR] [--timeout=SECONDS]
 *   tinvar renderer:check [--socket=PATH]
 *   tinvar renderer:restart [--socket=DIR] [--timeout=SECONDS]
 *
 * renderer:start runs the pool in the foreground -- a process manager's
 * child, or a terminal's -- and becomes it: this process is replaced by the
 * renderer's supervisor, so a signal sent to it reaches the pool itself and
 * nothing stands between them to forward or lose one. The pool is N
 * sandboxed renderers with a socket each in one directory (ADR 0028); the
 * PHP workers are pointed at the directory with TINVAR_SOCKET.
 *
 * The others find the pool by that directory -- --socket, else
 * TINVAR_SOCKET -- and its supervisor by the pid it records there:
 *
 *   - renderer:stop --graceful drains it: no new renders, the open ones
 *     finished, then gone. Without --graceful it stops at once, and a render
 *     in flight fails as any render does whose renderer goes away.
 *   - renderer:check asks each renderer, over its socket, how it is.
 *   - renderer:restart replaces the renderers one at a time, each only once
 *     its replacement answers: for a deploy, so they load the new build
 *     without a render refused.
 *
 * Run as a user of their own (ADR 0028), the renderers are started as that
 * user by the service manager, in a group shared with PHP's user:
 * --socket-group names it, and every socket and the directory are opened to
 * it alone. TINVAR_RENDERER_USER, where it is set, names the user:
 * renderer:start refuses to run as anyone else, and the others hold the
 * pool to it as a render would. stop and restart signal the supervisor, so
 * they work only as its user; under a service manager, it stops and
 * reloads the pool.
 */
final class RendererCommand
{
    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    /** Exit status when the command ran and what it was asked to do did not happen. */
    public const int FAILED = 1;

    /** The file in a pool's directory naming its supervisor; see src/pool.js. */
    public const string PID_FILE = 'renderer.pid';

    /**
     * How long a graceful stop is waited for when --timeout does not say: the
     * renderers' drain (60 s, PHP's overall cap on a render) and the
     * supervisor's grace after it.
     */
    private const int GRACEFUL_SECONDS = 70;

    private const int IMMEDIATE_SECONDS = 10;

    /** A rolling restart starts each replacement (20 s at most) and drains what it replaces. */
    private const int RESTART_SECONDS = 120;

    /** Each command's options: 'value' takes --name=VALUE, 'flag' is --name alone. */
    private const array OPTIONS = [
        'renderer:start' => ['processes' => 'value', 'app' => 'value', 'socket' => 'value', 'codec' => 'value', 'socket-group' => 'value'],
        'renderer:stop' => ['graceful' => 'flag', 'socket' => 'value', 'timeout' => 'value'],
        'renderer:check' => ['socket' => 'value'],
        'renderer:restart' => ['socket' => 'value', 'timeout' => 'value'],
    ];

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /** @var \Closure(string, list<string>, array<string, string>): int */
    private readonly \Closure $exec;

    /**
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     * @param (\Closure(string, list<string>, array<string, string>): int)|null $exec
     *        replaces this process with a program, returning only if it could not
     */
    public function __construct(
        private readonly string $projectRoot,
        ?\Closure $out = null,
        ?\Closure $err = null,
        private readonly ?string $workingDirectory = null,
        ?\Closure $exec = null,
    ) {
        $this->out = $out ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
            fflush(STDOUT);
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
        $this->exec = $exec ?? self::replaceProcess(...);
    }

    /** @param list<string> $arguments */
    public function run(string $command, array $arguments): int
    {
        try {
            $options = $this->parse($command, $arguments);

            return match ($command) {
                'renderer:start' => $this->start($options),
                'renderer:stop' => $this->stop($options),
                'renderer:check' => $this->check($options),
                'renderer:restart' => $this->restart($options),
                default => throw new ServeException("Unknown command '{$command}'."),
            };
        } catch (ServeException $e) {
            ($this->err)($e->getMessage());

            return self::USAGE;
        } catch (RendererException $e) {
            // A pool directory the trust checks refuse: said, not a crash.
            ($this->err)($e->getMessage());

            return self::FAILED;
        }
    }

    /** @param array<string, string> $options */
    private function start(array $options): int
    {
        $processes = $options['processes'] ?? null;
        if ($processes !== null && (preg_match('/^[1-9][0-9]?\z/', $processes) !== 1 || (int) $processes > ServeCommand::MAX_RENDERERS)) {
            throw new ServeException('--processes must be a whole number from 1 to ' . ServeCommand::MAX_RENDERERS . "; got '{$processes}'.");
        }
        $codec = $options['codec'] ?? 'msgpack';
        if (!\in_array($codec, ['msgpack', 'json-lines'], true)) {
            throw new ServeException("--codec must be msgpack or json-lines; got '{$codec}'.");
        }
        $group = isset($options['socket-group']) ? self::group($options['socket-group']) : null;

        // Started as the user PHP holds every socket to, or not at all: a
        // pool of PHP's own user would be refused by every render.
        $user = self::rendererUser();
        if ($user !== null && \function_exists('posix_geteuid') && posix_geteuid() !== $user->uid) {
            throw new ServeException(
                RendererUser::ENV . " says the renderers run as {$user->name}, and this is uid " . posix_geteuid()
                . ". Start the pool as {$user->name} -- its service manager's User= -- with --socket-group naming the group it shares with PHP's user.",
            );
        }
        // Without a group, its sockets are 0600: no one but this user, which
        // PHP is not, could connect to them.
        if ($user !== null && $group === null) {
            throw new ServeException(
                RendererUser::ENV . " says the renderers run as a user of their own, and PHP's user can reach their sockets only "
                . 'through a group the two share: name it with --socket-group.',
            );
        }

        $entry = $this->entry($options['app'] ?? null);
        $script = RendererBinary::script($this->projectRoot);
        $node = RendererBinary::node();

        // Half the cores: what serve gives renderers by default, here with
        // no worker count to bound it, since the workers are not ours.
        $count = $processes === null ? ServeCommand::defaultRenderers(ServeCommand::cpus(), ServeCommand::cpus()) : (int) $processes;
        $directory = $this->directory($options['socket'] ?? null);

        ($this->out)("TINVAR_SOCKET={$directory}");
        ($this->err)(sprintf(
            'Starting %d %s in %s. Point the PHP workers at it with TINVAR_SOCKET; '
            . 'renderer:stop --graceful drains and stops them.',
            $count,
            $count === 1 ? 'renderer' : 'renderers',
            $directory,
        ));

        return ($this->exec)(
            $node,
            [$script, $directory, $entry, "--processes={$count}", "--codec={$codec}", ...($group === null ? [] : ["--socket-group={$group}"])],
            [...RendererBinary::environment(), 'NODE_ENV' => 'production'],
        );
    }

    /** @param array<string, string> $options */
    private function stop(array $options): int
    {
        $graceful = isset($options['graceful']);
        $seconds = $this->seconds($options, $graceful ? self::GRACEFUL_SECONDS : self::IMMEDIATE_SECONDS);
        [$directory, $pid] = $this->supervisor($options);
        if ($pid === null) {
            return self::FAILED;
        }

        // SIGTERM drains; SIGQUIT is the pool's stop-at-once (src/pool.js).
        if (!$this->signal($pid, $graceful ? SIGTERM : SIGQUIT, $directory)) {
            return self::FAILED;
        }
        ($this->err)(sprintf('%s the renderer pool in %s (pid %d)...', $graceful ? 'Draining' : 'Stopping', $directory, $pid));

        $deadline = microtime(true) + $seconds;
        while (self::running($pid) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (self::running($pid)) {
            ($this->err)("It is still running after {$seconds}s.");

            return self::FAILED;
        }

        ($this->out)('stopped');

        return 0;
    }

    /** @param array<string, string> $options */
    private function check(array $options): int
    {
        $path = $this->target($options);
        if (!file_exists($path)) {
            ($this->err)("No renderer at {$path}.");

            return self::FAILED;
        }

        $pidFile = "{$path}/" . self::PID_FILE;
        if (is_dir($path) && is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            // As signal() would hold it: a pid is the supervisor only where
            // its command line is one. From another pid namespace -- PHP's
            // container beside the renderers' -- the pid is someone else's
            // here, or no one's, and the renderers' answers below are the
            // pool's state.
            $command = self::commandLine($pid);
            ($this->out)(\sprintf('supervisor  pid %d  %s', $pid, match (true) {
                self::running($pid) && self::isSupervisor($command) => 'running',
                self::running($pid) => 'not seen: another process has that pid here (the pool in another pid namespace, or gone)',
                default => 'not seen: no process has that pid here (the pool in another pid namespace, or gone)',
            }));
        }

        $answers = new RendererHealth(trust: self::trust())->checkAll($path);
        if ($answers === []) {
            ($this->err)("No renderer is listening in {$path}.");

            return self::FAILED;
        }

        $healthy = true;
        foreach ($answers as $socket => $answer) {
            if (\is_string($answer)) {
                $healthy = false;
                ($this->out)(\sprintf('%s  NOT ANSWERING: %s', basename($socket), $answer));
                continue;
            }
            ($this->out)(\sprintf(
                '%s  pid %d  %d rendering  %d served',
                basename($socket),
                $answer['pid'],
                $answer['inFlight'],
                $answer['served'],
            ));
        }

        return $healthy ? 0 : self::FAILED;
    }

    /** @param array<string, string> $options */
    private function restart(array $options): int
    {
        $seconds = $this->seconds($options, self::RESTART_SECONDS);
        [$directory, $pid] = $this->supervisor($options);
        if ($pid === null) {
            return self::FAILED;
        }

        // Done when no socket of the renderers there now is left and as many
        // answer as before: each is removed only once its replacement is up.
        $before = self::sockets($directory);
        if (!$this->signal($pid, SIGUSR2, $directory)) {
            return self::FAILED;
        }
        ($this->err)(sprintf('Replacing %d renderers in %s, one at a time...', \count($before), $directory));

        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $now = self::sockets($directory);
            if (array_intersect($before, $now) === [] && \count($now) >= \count($before)) {
                ($this->out)('restarted');

                return 0;
            }
            if (!self::running($pid)) {
                ($this->err)('The pool stopped during the restart.');

                return self::FAILED;
            }
            usleep(100_000);
        }

        // The pool keeps its old renderers when a replacement does not
        // start, and says so in its own output.
        ($this->err)("Not every renderer was replaced within {$seconds}s; a replacement that does not start leaves the old ones serving -- see the pool's output.");

        return self::FAILED;
    }

    /**
     * The pool's directory and its supervisor's pid, or null for the pid
     * when there is no pool running there to signal -- said why.
     *
     * The pid file is only as trustworthy as its directory -- whoever can
     * write there can name any process in it -- so the directory is held to
     * the checks a render holds it to first. Then the file is checked
     * against the process it names: a pool killed outright leaves its file
     * behind, and its pid may since belong to anything else this user runs.
     * Only a pool supervisor's command line passes, not any that mentions
     * renderer.js -- an editor open on it, or a single renderer, does.
     *
     * @param array<string, string> $options
     * @return array{string, int|null}
     */
    private function supervisor(array $options): array
    {
        $directory = $this->target($options);
        $pidFile = "{$directory}/" . self::PID_FILE;
        if (!is_dir($directory) || !is_file($pidFile)) {
            ($this->err)("No renderer pool in {$directory}: there is no " . self::PID_FILE . ' there.');

            return [$directory, null];
        }

        self::trust()->assertPool($directory);

        $text = trim((string) file_get_contents($pidFile));
        $pid = preg_match('/^[1-9][0-9]{0,9}\z/', $text) === 1 ? (int) $text : 0;
        $command = self::commandLine($pid);
        if ($pid === 0 || !self::running($pid) || !self::isSupervisor($command)) {
            ($this->err)("The renderer pool in {$directory} is not running" . ($pid > 0 ? " (pid {$pid} is not its supervisor)." : '.'));

            return [$directory, null];
        }

        return [$directory, $pid];
    }

    /**
     * Signals the pool's supervisor, saying why not when it cannot be: one
     * run as the renderers' own user is that user's, or root's, to signal.
     */
    private function signal(int $pid, int $signal, string $directory): bool
    {
        if (posix_kill($pid, $signal)) {
            return true;
        }

        ($this->err)(posix_get_last_error() === 1
            ? "The renderer pool in {$directory} (pid {$pid}) runs as another user, so this one cannot signal it. "
                . 'Stop or restart it as that user, or through its service manager (systemctl stop or reload).'
            : "Could not signal the renderer pool in {$directory} (pid {$pid}): " . posix_strerror(posix_get_last_error()) . '.');

        return false;
    }

    /**
     * The checks a render would make of the pool: held to the renderers'
     * own user where TINVAR_RENDERER_USER names one. Not refused for being
     * this process's user -- an operator may well check the pool as it.
     */
    private static function trust(): SocketTrust
    {
        return new SocketTrust(self::rendererUser()?->owners() ?? []);
    }

    /** @throws ServeException when TINVAR_RENDERER_USER names no one, or root */
    private static function rendererUser(): ?RendererUser
    {
        try {
            return RendererUser::fromEnvironment();
        } catch (\InvalidArgumentException $e) {
            throw new ServeException($e->getMessage());
        }
    }

    /**
     * --socket-group's gid: a number, or a group's name, looked up here --
     * PHP asks the system's group database, which /etc/group alone is not
     * everywhere -- so the renderer is handed a number.
     */
    private static function group(string $given): int
    {
        if (preg_match('/^[1-9][0-9]{0,9}\z/', $given) === 1) {
            return (int) $given;
        }
        $entry = preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}\z/', $given) === 1 && \function_exists('posix_getgrnam') ? posix_getgrnam($given) : false;
        if ($entry === false || $entry['gid'] === 0) {
            throw new ServeException("--socket-group must name a group other than root's, by name or number; got '{$given}'.");
        }

        return $entry['gid'];
    }

    /**
     * The command line of $pid, its arguments a space apart: from /proc
     * where there is one -- an image may have no ps -- and from ps
     * otherwise, -ww for the whole of it, however long its paths.
     */
    private static function commandLine(int $pid): string
    {
        if ($pid <= 0) {
            return '';
        }
        if (is_dir('/proc/self')) {
            $read = @file_get_contents("/proc/{$pid}/cmdline");

            return \is_string($read) ? trim(str_replace("\0", ' ', $read)) : '';
        }

        return trim((string) shell_exec("ps -ww -o args= -p {$pid} 2>/dev/null"));
    }

    /**
     * Whether a command line is a pool supervisor's: renderer.js run with
     * --processes, as renderer:start and serve both run it.
     */
    private static function isSupervisor(string $command): bool
    {
        return preg_match('#(^|[\s/])renderer\.js\s#', $command) === 1
            && preg_match('#\s--processes=[1-9][0-9]*(\s|$)#', $command) === 1;
    }

    /**
     * --socket, else TINVAR_SOCKET.
     *
     * @param array<string, string> $options
     */
    private function target(array $options): string
    {
        $path = $options['socket'] ?? (getenv('TINVAR_SOCKET') ?: null);
        if ($path === null) {
            throw new ServeException('Which renderer? Pass --socket=PATH, or set TINVAR_SOCKET.');
        }

        return rtrim($path, '/');
    }

    /** @param array<string, string> $options */
    private function seconds(array $options, int $default): int
    {
        $given = $options['timeout'] ?? null;
        if ($given === null) {
            return $default;
        }
        if (preg_match('/^[1-9][0-9]{0,3}\z/', $given) !== 1) {
            throw new ServeException("--timeout must be a whole number of seconds from 1 to 9999; got '{$given}'.");
        }

        return (int) $given;
    }

    /** @return list<string> the socket names in a pool's directory */
    private static function sockets(string $directory): array
    {
        return array_values(array_filter(scandir($directory) ?: [], static fn (string $name): bool => str_ends_with($name, '.sock')));
    }

    /**
     * Signal 0 asks without sending; EPERM is a process that exists under
     * another user. A zombie answers too -- exited, and waiting for its
     * parent to collect it, which may be a while or never -- so it is asked
     * after as well, and counts as gone.
     */
    private static function running(int $pid): bool
    {
        if ($pid <= 0 || (!posix_kill($pid, 0) && posix_get_last_error() !== 1)) {
            return false;
        }

        return !str_starts_with(trim((string) shell_exec("ps -o stat= -p {$pid} 2>/dev/null")), 'Z');
    }

    /**
     * The app's built rsc entry: --app's, or the working directory's -- in
     * dist/, unless TINVAR_BUILD_DIR names a build of its own, as serve
     * reads it (BuildProfile, ADR 0066).
     */
    private function entry(?string $given): string
    {
        $cwd = $this->workingDirectory ?? (string) getcwd();
        $app = realpath($given === null ? $cwd : (str_starts_with($given, '/') ? $given : "{$cwd}/{$given}"));
        if ($app === false || !is_dir($app)) {
            throw new ServeException('No app at ' . ($given ?? $cwd) . '.');
        }

        try {
            $entry = BuildProfile::buildDirectory($app) . '/rsc/index.js';
        } catch (\RuntimeException $e) {
            throw new ServeException($e->getMessage());
        }
        if (!is_file($entry)) {
            throw new ServeException("{$app} is not built: there is no {$entry}. Build it first (npx vite build in {$app}).");
        }

        return $entry;
    }

    /**
     * Where the pool goes: --socket, else TINVAR_SOCKET, else a fresh private
     * directory -- short, for macOS's 104-byte socket paths, and no one
     * else's to write.
     */
    private function directory(?string $given): string
    {
        $given ??= getenv('TINVAR_SOCKET') ?: null;
        if ($given === null) {
            // One a pool killed outright left behind -- its supervisor could
            // clean up nothing -- goes now, as serve's do.
            PrivateDirectory::removeAbandoned('tinvar-pool');

            // Its owner is this process, which the pool's supervisor is
            // about to become, so a later start can tell when it is gone.
            return PrivateDirectory::create('tinvar-pool', owner: getmypid() ?: null);
        }
        if (!str_starts_with($given, '/')) {
            throw new ServeException("--socket must be an absolute path; got '{$given}'.");
        }

        return rtrim($given, '/');
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string>
     */
    private function parse(string $command, array $arguments): array
    {
        $allowed = self::OPTIONS[$command] ?? throw new ServeException("Unknown command '{$command}'.");

        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z]+(?:-[a-z]+)*)(?:=(.*))?\z/s', $argument, $m) === 1
                && ($allowed[$m[1]] ?? null) === (isset($m[2]) ? 'value' : 'flag')) {
                $options[$m[1]] = $m[2] ?? '';
            } else {
                $usage = [];
                foreach ($allowed as $name => $kind) {
                    $usage[] = $kind === 'flag' ? "[--{$name}]" : "[--{$name}=...]";
                }

                throw new ServeException("Unknown argument '{$argument}'. Usage: tinvar {$command} " . implode(' ', $usage));
            }
        }

        return $options;
    }

    /**
     * pcntl_exec where there is one: the pool becomes this process, and this
     * returns only if it could not. Without it, the pool runs as a child that
     * this process waits for, and signals must be sent to the pool's own pid,
     * which its directory records -- renderer:stop does.
     *
     * Neither way hands the sandboxed renderers anything this process holds.
     * The child is given /dev/null for each (ChildDescriptors). pcntl_exec
     * keeps every descriptor not close-on-exec, which PHP's are not, and
     * PHP can close none it did not open as a stream; the supervisor closes
     * them itself before it starts a renderer (closeInheritedDescriptors()
     * in @tinvar/renderer's sandbox.js), and the renderers inherit none.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $env
     * @return int the exit status to leave with
     */
    private static function replaceProcess(string $program, array $arguments, array $env): int
    {
        if (\function_exists('pcntl_exec')) {
            pcntl_exec($program, $arguments, $env);
            fwrite(STDERR, "Could not start {$program}: " . pcntl_strerror(pcntl_get_last_error()) . "\n");

            return 1;
        }

        $process = proc_open([$program, ...$arguments], ChildDescriptors::closing([0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR]), $pipes, null, $env);
        if (!\is_resource($process)) {
            return 1;
        }

        return proc_close($process);
    }
}
