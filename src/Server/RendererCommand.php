<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Support\PrivateDirectory;

/**
 * The spec's renderer lifecycle commands, for a pool run on its own rather
 * than under `tinvar serve`:
 *
 *   tinvar renderer:start [--processes=N] [--app=DIR] [--socket=DIR] [--codec=msgpack|json-lines]
 *
 * renderer:start runs the pool in the foreground -- a process manager's
 * child, or a terminal's -- and becomes it: this process is replaced by the
 * renderer's supervisor, so a signal sent to it reaches the pool itself and
 * nothing stands between them to forward or lose one. The pool is N
 * sandboxed renderers with a socket each in one directory (ADR 0028); the
 * PHP workers are pointed at the directory with TINVAR_SOCKET.
 */
final class RendererCommand
{
    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    /** Variables the renderer may see; it refuses to start holding anything secret. */
    private const array RENDERER_ENV = ['PATH', 'TZ', 'LANG', 'LC_ALL'];

    private const array OPTIONS = [
        'renderer:start' => ['processes', 'app', 'socket', 'codec'],
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
                default => throw new ServeException("Unknown command '{$command}'."),
            };
        } catch (ServeException $e) {
            ($this->err)($e->getMessage());

            return self::USAGE;
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
            [$script, $directory, $entry, "--processes={$count}", "--codec={$codec}"],
            [...array_intersect_key(getenv(), array_flip(self::RENDERER_ENV)), 'NODE_ENV' => 'production'],
        );
    }

    /** The app's built rsc entry: --app's, or the working directory's. */
    private function entry(?string $given): string
    {
        $cwd = $this->workingDirectory ?? (string) getcwd();
        $app = realpath($given === null ? $cwd : (str_starts_with($given, '/') ? $given : "{$cwd}/{$given}"));
        if ($app === false || !is_dir($app)) {
            throw new ServeException('No app at ' . ($given ?? $cwd) . '.');
        }

        $entry = "{$app}/dist/rsc/index.js";
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
            if (preg_match('/^--([a-z]+)=(.*)\z/s', $argument, $m) === 1 && \in_array($m[1], $allowed, true)) {
                $options[$m[1]] = $m[2];
            } else {
                throw new ServeException(
                    "Unknown argument '{$argument}'. Usage: tinvar {$command} "
                    . implode(' ', array_map(static fn (string $o): string => "[--{$o}=...]", $allowed)),
                );
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

        $process = proc_open([$program, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, $env);
        if (!\is_resource($process)) {
            return 1;
        }

        return proc_close($process);
    }
}
