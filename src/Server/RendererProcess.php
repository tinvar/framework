<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Support\PrivateDirectory;

/**
 * A renderer started for a build step -- `tinvar build:static` -- and
 * stopped when it is done.
 *
 * Started as `tinvar serve` starts one: a socket in a fresh private
 * directory, short enough for macOS's 104-byte limit; the production React
 * build; and a scrubbed environment, since the renderer refuses to start
 * holding anything secret-shaped and should never be handed it.
 */
final class RendererProcess
{
    /** Variables the renderer may see. */
    private const array ENV = ['PATH', 'TZ', 'LANG', 'LC_ALL'];

    /** @param resource $process */
    private function __construct(
        private $process,
        public readonly string $socketPath,
        private readonly string $directory,
    ) {}

    /**
     * @param string $projectRoot where node_modules and the renderer package are
     * @param string $entry the app's built rsc entry
     */
    public static function start(string $projectRoot, string $entry, float $timeoutSeconds = 20.0): self
    {
        $bin = null;
        foreach (["{$projectRoot}/node_modules/@tinvar/renderer/bin/renderer.js", "{$projectRoot}/packages/renderer/bin/renderer.js"] as $candidate) {
            if (is_file($candidate)) {
                $bin = $candidate;
                break;
            }
        }
        if ($bin === null) {
            throw new \RuntimeException("The renderer is not installed: run npm ci in {$projectRoot}.");
        }
        if (!is_file($entry)) {
            throw new \RuntimeException("No build at {$entry}: build the app first.");
        }

        $directory = PrivateDirectory::create('tinvar-build');
        $socket = "{$directory}/renderer.sock";
        $log = "{$directory}/renderer.log";

        $process = proc_open(
            ['node', $bin, $socket, $entry],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $projectRoot,
            [...array_intersect_key(getenv(), array_flip(self::ENV)), 'NODE_ENV' => 'production'],
        );
        if (!\is_resource($process)) {
            PrivateDirectory::remove($directory);

            throw new \RuntimeException('Could not start the renderer.');
        }

        $renderer = new self($process, $socket, $directory);
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

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, \defined('SIGTERM') ? SIGTERM : 15);
            }
            proc_close($this->process);
        }
        PrivateDirectory::remove($this->directory);
    }
}
