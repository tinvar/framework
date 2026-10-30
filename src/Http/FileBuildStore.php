<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * A BuildHistory kept in one JSON file: the default store, for an
 * application served from one machine.
 *
 * Read on every check, and parsed only when its bytes differ from the bytes
 * last parsed. Compared whole, not by a stat() signature: mtime and ctime
 * count whole seconds, and a write renamed into place can be given an inode
 * freed by an earlier one, so a revocation written within the second of the
 * last read, at the same length, left every field of the signature alike --
 * and a worker that had cached the history before it went on accepting the
 * build. The same bytes are the same history by construction, so this cannot
 * miss a change. Reading a history of thirty releases, under 3 KB, and
 * comparing it costs about 10 microseconds against a stat()'s one -- a
 * fiftieth of a percent of a 5 ms render, to make a revocation certain.
 *
 * Not a secret, but not anyone's to write: whoever can write the file can
 * lift a revocation. Keep it where only the deploying user can, beside the
 * application's other state rather than in its build output, which a deploy
 * replaces.
 */
final class FileBuildStore implements BuildStore
{
    /** The bytes the cached history was parsed from, or null for none. */
    private ?string $bytes = null;

    private ?BuildHistory $history = null;

    /** One empty history for a missing file, so its identity is stable too. */
    private ?BuildHistory $empty = null;

    /** @var \Closure(resource, string): bool */
    private readonly \Closure $sync;

    /**
     * @param (\Closure(resource, string): bool)|null $sync syncs an open file to the disk --
     *        'file', the history written under its private name, or 'directory', the rename --
     *        and says whether it could: fsync() unless given. A test's seam, to see each sync
     *        made where write() says and a failed one refused.
     */
    public function __construct(public readonly string $path, ?\Closure $sync = null)
    {
        $this->sync = $sync ?? static fn ($handle, string $what): bool => @fsync($handle);

        // Absolute: the CLI and the workers start in different directories,
        // and a relative path would have each keep a history of its own.
        if (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            throw new \InvalidArgumentException("A build history file must be an absolute path; got {$path}.");
        }
    }

    public function read(): BuildHistory
    {
        $raw = $this->contents();

        if ($raw === null) {
            $this->bytes = null;

            return $this->history = $this->empty ??= new BuildHistory();
        }

        if ($raw === $this->bytes && $this->history !== null) {
            return $this->history;
        }

        // Parsed before it is remembered: a file that fails to parse is
        // tried again on the next read rather than cached as broken, so the
        // moment it is fixed every worker has it.
        $history = $this->parse($raw);
        $this->bytes = $raw;

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
            $raw = $this->contents();
            $next = $change($raw === null ? new BuildHistory() : $this->parse($raw));

            $this->write($directory, $next);

            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The file's bytes, or null when there is no file -- nothing recorded
     * yet, which is an empty history rather than an error. One that exists
     * and cannot be read is an error, which a registry fails closed on.
     */
    private function contents(): ?string
    {
        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);
        if ($raw !== false) {
            return $raw;
        }

        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            return null;
        }

        throw new \RuntimeException("Cannot read the build history at {$this->path}.");
    }

    private function parse(string $raw): BuildHistory
    {
        try {
            return BuildHistory::fromArray(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (\JsonException|\UnexpectedValueException $e) {
            throw new \RuntimeException("The build history at {$this->path} cannot be read: {$e->getMessage()}.", previous: $e);
        }
    }

    /**
     * Written whole under a private name and renamed into place, so a worker
     * reading at that moment sees the old history or the new one, never half.
     *
     * And synced to the disk on both sides of the rename. Renamed before its
     * bytes were on the disk, an OS crash could leave the name on a file
     * with none in it -- a history that cannot be read, and every build
     * refused; and with the rename not yet on the disk, the old file could
     * come back under the name -- a build revoked a moment before served
     * again.
     */
    private function write(string $directory, BuildHistory $history): void
    {
        $json = json_encode($history->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $temporary = $directory . '/.' . basename($this->path) . '.' . bin2hex(random_bytes(6));
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new \RuntimeException("Cannot write the build history beside {$this->path}.");
        }
        try {
            $written = @fwrite($handle, $json) === \strlen($json) && ($this->sync)($handle, 'file');
        } finally {
            fclose($handle);
        }
        if (!$written) {
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

        // The rename, which lives in the directory. In force already for
        // every worker, so a directory that cannot be synced is not a failed
        // change -- only one less certain to outlive a crash.
        $listing = @fopen($directory, 'r');
        if ($listing !== false) {
            ($this->sync)($listing, 'directory');
            fclose($listing);
        }
    }
}
