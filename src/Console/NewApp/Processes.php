<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

use Tinvar\Support\ChildDescriptors;

/**
 * The commands `tinvar new` runs -- Composer, npm, the new application's
 * own tinvar -- each with its output passed on as it comes, and its
 * exit status; a test hands in one that records them instead.
 */
class Processes
{
    /** @param \Closure(string): void $out where each command's output goes */
    public function __construct(private readonly \Closure $out) {}

    /**
     * @param list<string> $command
     * @param array<string, string>|null $env the environment; this process's by default
     */
    public function run(array $command, string $directory, ?array $env = null): int
    {
        ($this->out)('$ ' . implode(' ', array_map(self::quote(...), $command)) . "\n");
        $process = proc_open($command, ChildDescriptors::closing([0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]), $pipes, $directory, $env);
        if (!\is_resource($process)) {
            ($this->out)("{$command[0]} did not start in {$directory}.\n");

            return 127;
        }
        // Both streams, as each has something: read one after the other, a
        // child that filled the second while this waited on the first would
        // never finish.
        $open = [$pipes[1], $pipes[2]];
        while ($open !== []) {
            $read = $open;
            $write = $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = (string) fread($stream, 8192);
                if ($chunk !== '') {
                    ($this->out)($chunk);
                } elseif (feof($stream)) {
                    fclose($stream);
                    $open = array_values(array_filter($open, static fn ($s): bool => $s !== $stream));
                }
            }
        }

        return proc_close($process);
    }

    /** These processes, with what each command prints dropped rather than passed on. */
    public function quietly(): static
    {
        return clone($this, ['out' => static function (string $text): void {}]);
    }

    /** Whether $program is on the PATH. */
    public function has(string $program): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_file("{$directory}/{$program}") && is_executable("{$directory}/{$program}")) {
                return true;
            }
        }

        return false;
    }

    private static function quote(string $argument): string
    {
        return preg_match('#^[A-Za-z0-9_./:=@,+-]+\z#', $argument) === 1 ? $argument : escapeshellarg($argument);
    }
}
