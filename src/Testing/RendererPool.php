<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Server\RendererBinary;
use Tinvar\Support\PrivateDirectory;

/**
 * The renderers a test process renders pages through: one pool per process
 * and app entry, started on the first render() and kept for every later one.
 *
 * "The suite starts one Node renderer pool per run." The pool is a directory
 * of sockets (ADR 0028), named before anything starts, so an application can
 * be built on it while no render has been asked for: a page test that never
 * renders never starts Node. Under `pest --parallel` each worker is a process
 * and has a pool of its own -- the spec's single pool shared by every worker
 * would need a process that outlives each of them and is owned by none, and
 * a renderer that a worker can reach but did not start is one it cannot
 * vouch for (ADR 0043).
 *
 * Started as `tinvar serve` starts one: the production React build, a
 * scrubbed environment, a private directory short enough for macOS's socket
 * limit. With --exit-with-stdin, on a pipe this process holds: killed
 * outright, a test process takes its renderers with it rather than leaving
 * them listening.
 */
final class RendererPool
{
    /** Variables the renderer may see. */
    private const array ENV = ['PATH', 'TZ', 'LANG', 'LC_ALL'];

    /** @var array<string, self> by entry and size, for this process */
    private static array $pools = [];

    /** The directory of sockets an application is built on. */
    public readonly string $path;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null the renderer's standard input, held open while it should run */
    private $stdin = null;

    private readonly string $log;

    private readonly int $owner;

    /**
     * @param string $entry the application's built rsc entry
     * @param string $projectRoot where node_modules and the renderer package are
     * @param int $processes renderers in the pool
     */
    private function __construct(
        private readonly string $entry,
        private readonly string $projectRoot,
        private readonly int $processes,
    ) {
        $this->owner = getmypid() ?: 0;
        PrivateDirectory::removeAbandoned('tinvar-testpool');
        $this->path = PrivateDirectory::create('tinvar-testpool', owner: $this->owner);
        $this->log = "{$this->path}/renderer.log";

        register_shutdown_function(function (): void {
            // Not in a forked child, which runs shutdown functions too.
            if ((getmypid() ?: 0) === $this->owner) {
                $this->stop();
            }
        });
    }

    /** This process's pool for $entry, made -- not started -- on first use. */
    public static function for(string $entry, string $projectRoot, int $processes = 1): self
    {
        if ($processes < 1 || $processes > 64) {
            throw new \InvalidArgumentException('A renderer pool has 1 to 64 processes.');
        }

        return self::$pools["{$entry}\0{$processes}"] ??= new self($entry, $projectRoot, $processes);
    }

    /** Starts the pool unless it is running, and waits until every renderer listens. */
    public function start(float $timeoutSeconds = 30.0): void
    {
        if ($this->running()) {
            return;
        }
        $this->stop(keepDirectory: true);

        if (!is_file($this->entry)) {
            throw new \RuntimeException("No app entry at {$this->entry}: build the application before rendering through it.");
        }

        $process = proc_open(
            [
                RendererBinary::node(),
                RendererBinary::script($this->projectRoot),
                $this->path,
                $this->entry,
                "--processes={$this->processes}",
                '--exit-with-stdin',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']],
            $pipes,
            $this->projectRoot,
            [...array_intersect_key(getenv(), array_flip(self::ENV)), 'NODE_ENV' => 'production'],
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the renderer pool.');
        }
        $this->process = $process;
        $this->stdin = $pipes[0];

        $deadline = microtime(true) + $timeoutSeconds;
        while (\count($this->sockets()) < $this->processes) {
            if (!$this->running()) {
                $log = (string) @file_get_contents($this->log);
                $this->stop(keepDirectory: true);

                throw new \RuntimeException("The renderer pool exited before listening.\n{$log}");
            }
            if (microtime(true) > $deadline) {
                $this->stop(keepDirectory: true);

                throw new \RuntimeException("The renderer pool did not listen within {$timeoutSeconds}s.");
            }
            usleep(50_000);
        }
    }

    public function running(): bool
    {
        return \is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    /** Stops the pool; the directory goes too unless it is to be started again. */
    public function stop(bool $keepDirectory = false): void
    {
        if (\is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        $this->stdin = null;
        if (\is_resource($this->process)) {
            proc_terminate($this->process, SIGTERM);
            proc_close($this->process);
        }
        $this->process = null;

        if (!$keepDirectory) {
            PrivateDirectory::remove($this->path);
        }
    }

    /** What the renderers have written, for a test that wants to know why one failed. */
    public function log(): string
    {
        return (string) @file_get_contents($this->log);
    }

    /** @return list<string> */
    private function sockets(): array
    {
        clearstatcache();

        return array_values(array_filter(
            scandir($this->path) ?: [],
            static fn (string $name): bool => str_ends_with($name, '.sock'),
        ));
    }
}
