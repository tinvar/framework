<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * The process's terminal: keys from standard input, frames to standard
 * error -- so standard output stays the command's own, to pipe or redirect
 * -- and raw mode through stty(1).
 *
 * Interactive only where a person can be: standard input and standard
 * error both terminals, not Windows (no stty), and nothing saying nobody is
 * there -- CI or TINVAR_NO_INTERACTION set, or a PHPUnit run, whose
 * terminal is the developer's but whose questions nobody would answer.
 *
 * Raw mode is left however the process leaves it: by restore(), which
 * every prompt calls in a finally; at shutdown, after exit() or a fatal
 * error; and on SIGTERM, SIGHUP, SIGINT or SIGQUIT where pcntl is loaded,
 * which restore the terminal, then exit with 128 and the signal.
 */
final class StreamTerminal implements Terminal
{
    /** @var array<int, self> every terminal in raw mode now, by id */
    private static array $raw = [];

    private static bool $shutdownRegistered = false;

    private ?string $saved = null;

    /** @var array<int, mixed> the signal handlers raw() replaced, to put back */
    private array $handlers = [];

    /** @var array{int, int, float}|null lines and columns, and when they were read */
    private ?array $size = null;

    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /**
     * @param resource|null $input standard input by default
     * @param resource|null $output standard error by default
     * @param array<string, string>|null $environment the process's own by default
     */
    public function __construct($input = null, $output = null, private readonly ?array $environment = null)
    {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDERR;
    }

    public function interactive(): bool
    {
        if (\PHP_OS_FAMILY === 'Windows' || \defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__')) {
            return false;
        }
        foreach (['CI', 'TINVAR_NO_INTERACTION'] as $variable) {
            $value = strtolower(trim($this->env($variable)));
            if ($value !== '' && $value !== '0' && $value !== 'false') {
                return false;
            }
        }

        return stream_isatty($this->input) && stream_isatty($this->output);
    }

    public function colors(): bool
    {
        return $this->env('NO_COLOR') === '' && $this->env('TERM') !== 'dumb' && stream_isatty($this->output);
    }

    public function animates(): bool
    {
        return \function_exists('pcntl_fork') && \function_exists('posix_kill') && $this->interactive();
    }

    public function columns(): int
    {
        return $this->size()[1];
    }

    public function lines(): int
    {
        return $this->size()[0];
    }

    public function read(): string
    {
        $read = fread($this->input, 1024);

        return $read === false ? '' : $read;
    }

    public function write(string $text): void
    {
        fwrite($this->output, $text);
    }

    public function raw(): void
    {
        if ($this->saved !== null) {
            return;
        }
        $saved = $this->stty(['-g']);
        if ($saved === null || $this->stty(['-icanon', '-isig', '-echo', 'min', '1', 'time', '0']) === null) {
            throw new \RuntimeException('Could not put the terminal in raw mode with stty.');
        }
        $this->saved = $saved;
        self::$raw[spl_object_id($this)] = $this;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                foreach (self::$raw as $terminal) {
                    $terminal->restore();
                }
            });
        }
        if (\function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGHUP, SIGINT, SIGQUIT] as $signal) {
                $this->handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, function (int $signal): void {
                    $this->restore();
                    exit(128 + $signal);
                });
            }
        }
    }

    public function restore(): void
    {
        if ($this->saved === null) {
            return;
        }
        $saved = $this->saved;
        $this->saved = null;
        unset(self::$raw[spl_object_id($this)]);
        $this->stty([$saved]);
        // The cursor a frame hid, shown again whatever left it hidden.
        $this->write("\e[?25h");
        foreach ($this->handlers as $signal => $handler) {
            pcntl_signal($signal, \is_callable($handler) || \is_int($handler) ? $handler : SIG_DFL);
        }
        $this->handlers = [];
    }

    /**
     * Lines and columns: stty's, or LINES and COLUMNS, or 24 by 80. Read
     * again at most twice a second, so a resize is seen and a frame drawn
     * at each key does not run stty each time.
     *
     * @return array{int, int}
     */
    private function size(): array
    {
        $now = hrtime(true) / 1e9;
        if ($this->size === null || $now - $this->size[2] >= 0.5) {
            [$lines, $columns] = [0, 0];
            $answer = $this->stty(['size']);
            if ($answer !== null && preg_match('/^(\d+) (\d+)$/', $answer, $m) === 1) {
                [$lines, $columns] = [(int) $m[1], (int) $m[2]];
            }
            $lines = $lines > 0 ? $lines : (ctype_digit($this->env('LINES')) ? (int) $this->env('LINES') : 0);
            $columns = $columns > 0 ? $columns : (ctype_digit($this->env('COLUMNS')) ? (int) $this->env('COLUMNS') : 0);
            $this->size = [$lines > 0 ? $lines : 24, $columns > 0 ? $columns : 80, $now];
        }

        return [$this->size[0], $this->size[1]];
    }

    /**
     * stty run against this terminal's input, and what it printed.
     *
     * @param list<string> $arguments
     */
    private function stty(array $arguments): ?string
    {
        if (\PHP_OS_FAMILY === 'Windows' || !stream_isatty($this->input)) {
            return null;
        }
        $process = proc_open(['stty', ...$arguments], [0 => $this->input, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return null;
        }
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $out : null;
    }

    private function env(string $name): string
    {
        $value = $this->environment === null ? getenv($name) : ($this->environment[$name] ?? false);

        return \is_string($value) ? $value : '';
    }
}
