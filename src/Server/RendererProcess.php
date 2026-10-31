<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Render\RendererUser;
use Tinvar\Support\ChildDescriptors;
use Tinvar\Support\PrivateDirectory;

/**
 * A renderer started for a build step -- `tinvar build:static` -- and
 * stopped when it is done.
 *
 * Started as `tinvar serve` starts one: a socket in a fresh private
 * directory, short enough for macOS's 104-byte limit; the production React
 * build; and a scrubbed environment, since the renderer refuses to start
 * holding anything secret-shaped and should never be handed it.
 *
 * Never where TINVAR_RENDERER_USER names the renderers' own user: started
 * here, a renderer runs as this process's user, which is what naming one
 * rules out. The pool that user runs renders instead (ADR 0028).
 */
final class RendererProcess
{
    /** The prefix of its private directory, which a later start sweeps when its owner has gone. */
    private const string PREFIX = 'tinvar-build';

    /** Seconds stop() gives the renderer after SIGTERM before SIGKILL. */
    public const int STOP_SECONDS = 10;

    /**
     * @param resource $process
     * @param resource $tether its standard input, which this process holds and never writes to
     */
    private function __construct(
        private $process,
        private $tether,
        public readonly string $socketPath,
        private readonly string $directory,
    ) {}

    /**
     * @param string $projectRoot where node_modules and the renderer package are
     * @param string $entry the app's built rsc entry
     */
    public static function start(string $projectRoot, string $entry, float $timeoutSeconds = 20.0): self
    {
        try {
            $user = RendererUser::fromEnvironment();
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if ($user !== null) {
            throw new \RuntimeException(
                RendererUser::ENV . " says the renderers run as {$user->name}, and one started here would run as this user. "
                . 'Render through their pool: set TINVAR_SOCKET to its directory.',
            );
        }

        try {
            $bin = RendererBinary::script($projectRoot);
        } catch (ServeException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if (!is_file($entry)) {
            throw new \RuntimeException("No build at {$entry}: build the app first.");
        }

        // One a build killed outright left behind -- it could clean up
        // nothing -- goes now, as serve's do; and this one is marked as this
        // process's, so a later start can tell when it is gone.
        PrivateDirectory::removeAbandoned(self::PREFIX);
        $directory = PrivateDirectory::create(self::PREFIX, owner: getmypid() ?: null);
        $socket = "{$directory}/renderer.sock";
        $log = "{$directory}/renderer.log";

        // Its standard input a pipe this process holds and never writes to,
        // as serve starts one: killed outright -- SIGKILL, a crash -- this
        // process can stop nothing, but the pipe closes, and the renderer
        // stops when its input ends (--exit-with-stdin).
        $process = proc_open(
            ['node', $bin, $socket, $entry, '--exit-with-stdin'],
            // Nothing else this process holds reaches the renderer (ChildDescriptors).
            ChildDescriptors::closing([0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']]),
            $pipes,
            $projectRoot,
            [...RendererBinary::environment(), 'NODE_ENV' => 'production'],
        );
        if (!\is_resource($process)) {
            PrivateDirectory::remove($directory);

            throw new \RuntimeException('Could not start the renderer.');
        }

        $renderer = new self($process, $pipes[0], $socket, $directory);
        $deadline = microtime(true) + $timeoutSeconds;
        while (!file_exists($socket)) {
            if (!proc_get_status($process)['running'] || microtime(true) > $deadline) {
                $output = (string) @file_get_contents($log);
                $renderer->stop();

                throw new \RuntimeException("The renderer did not start listening:\n{$output}");
            }
            usleep(50_000);
        }

        return $renderer;
    }

    /**
     * SIGTERM, and SIGKILL to a renderer that has not gone within
     * STOP_SECONDS: proc_close() waits for the child, and one that would not
     * stop held the build that started it for ever.
     */
    public function stop(): void
    {
        if (\is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, \defined('SIGTERM') ? SIGTERM : 15);
            }
            $deadline = microtime(true) + self::STOP_SECONDS;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
                usleep(50_000);
            }
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, \defined('SIGKILL') ? SIGKILL : 9);
            }
            if (\is_resource($this->tether)) {
                fclose($this->tether);
            }
            proc_close($this->process);
        }
        PrivateDirectory::remove($this->directory);
    }
}
