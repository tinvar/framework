<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * A BuildHistory kept in one JSON file: the default store, for an
 * application served from one machine.
 *
 * Read per request and cheap to: the file is parsed once per worker and then
 * only when it changes, which a stat() answers. Every write replaces the file
 * by renaming a complete one over it, so the inode changes with every change
 * -- a signature that does not depend on the clock's resolution, where two
 * writes within one second of the same length would leave the mtime and size
 * alike and a worker on the old revocation list.
 *
 * Not a secret, but not anyone's to write: whoever can write the file can
 * lift a revocation. Keep it where only the deploying user can, beside the
 * application's other state rather than in its build output, which a deploy
 * replaces.
 */
final class FileBuildStore implements BuildStore
{
    /** The stat signature of the file last parsed, or null for none. */
    private ?string $seen = null;

    private ?BuildHistory $history = null;

    /** One empty history for a missing file, so its identity is stable too. */
    private ?BuildHistory $empty = null;

    public function __construct(public readonly string $path)
    {
        // Absolute: the CLI and the workers start in different directories,
        // and a relative path would have each keep a history of its own.
        if (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            throw new \InvalidArgumentException("A build history file must be an absolute path; got {$path}.");
        }
    }

    public function read(): BuildHistory
    {
        clearstatcache(true, $this->path);
        $stat = is_file($this->path) ? @stat($this->path) : false;

        if ($stat === false) {
            $this->seen = null;

            return $this->history = $this->empty ??= new BuildHistory();
        }

        $signature = "{$stat['dev']}:{$stat['ino']}:{$stat['size']}:{$stat['mtime']}:{$stat['ctime']}";
        if ($signature === $this->seen && $this->history !== null) {
            return $this->history;
        }

        // Parsed before it is remembered: a file that fails to parse is
        // tried again on the next read rather than cached as broken, so the
        // moment it is fixed every worker has it.
        $history = $this->parse();
        $this->seen = $signature;

        return $this->history = $history;
    }

    public function update(\Closure $change): BuildHistory
    {
        $directory = \dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory} for the build history.");
        }

        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("Cannot open {$this->path}.lock to change the build history.");
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock {$this->path}.lock to change the build history.");
            }

            // Fresh from disk, never the cached copy: another command may
            // have written since this process last looked.
            clearstatcache(true, $this->path);
            $next = $change(is_file($this->path) ? $this->parse() : new BuildHistory());

            $this->write($directory, $next);

            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function parse(): BuildHistory
    {
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read the build history at {$this->path}.");
        }

        try {
            return BuildHistory::fromArray(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (\JsonException|\UnexpectedValueException $e) {
            throw new \RuntimeException("The build history at {$this->path} cannot be read: {$e->getMessage()}.", previous: $e);
        }
    }

    /**
     * Written whole under a private name and renamed into place, so a worker
     * reading at that moment sees the old history or the new one, never half.
     */
    private function write(string $directory, BuildHistory $history): void
    {
        $json = json_encode($history->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $temporary = $directory . '/.' . basename($this->path) . '.' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, $json) !== \strlen($json)) {
            @unlink($temporary);
            throw new \RuntimeException("Cannot write the build history beside {$this->path}.");
        }

        // Readable by the workers, who may run as another user than the
        // operator; written only by whoever owns it.
        @chmod($temporary, 0o644);

        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException("Cannot move the build history into {$this->path}.");
        }
    }
}
