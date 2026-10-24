<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * Files in a directory on this machine's disk.
 *
 * The root is made 0700 and every file in it 0600: what customers upload is
 * theirs, and no other account on the machine -- the renderer's above all,
 * which runs third-party npm code -- has any business reading it. Keep the
 * root outside anything a web server serves as files and outside the
 * project tree, for the reason the app key is kept there: the renderer's
 * sandbox reads node_modules, and a workspace link can lead anywhere.
 *
 * A write goes to a temporary file beside its destination and is renamed
 * into place, which is atomic on one filesystem: a reader sees the old file
 * or the new one, never part of either.
 */
final readonly class LocalStorage implements Storage
{
    private string $root;

    public function __construct(string $root)
    {
        if (!str_starts_with($root, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $root) !== 1) {
            // A relative root resolves against whichever directory the
            // process started in, so the server and the CLI pruning its
            // uploads would each look in a store of their own.
            throw new \InvalidArgumentException("A storage root must be an absolute path; got '{$root}'.");
        }

        if (!is_dir($root) && !@mkdir($root, 0o700, true) && !is_dir($root)) {
            throw new StorageException("The storage root '{$root}' does not exist and cannot be made.");
        }

        $real = realpath($root);
        if ($real === false) {
            throw new StorageException("The storage root '{$root}' cannot be resolved.");
        }

        $this->root = rtrim($real, '/\\');
    }

    public function put(string $key, mixed $stream): void
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('Storage::put() takes a readable stream.');
        }

        $path = $this->path($key);
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new StorageException('A directory for a stored file cannot be made.');
        }
        $this->assertInside($directory);

        // Created exclusively, under a name nobody else can be using, so a
        // file planted at the temporary name is never written through.
        $temporary = $directory . '/.tmp-' . bin2hex(random_bytes(8));
        $out = @fopen($temporary, 'xb');
        if ($out === false) {
            throw new StorageException('A stored file cannot be written.');
        }

        try {
            @chmod($temporary, 0o600);
            if (stream_copy_to_stream($stream, $out) === false || !fflush($out)) {
                throw new StorageException('A stored file could not be written to the end.');
            }
            fclose($out);
            $out = null;

            if (!@rename($temporary, $path)) {
                throw new StorageException('A stored file cannot be moved into place.');
            }
        } catch (\Throwable $e) {
            if (\is_resource($out)) {
                fclose($out);
            }
            @unlink($temporary);

            throw $e;
        }
    }

    public function read(string $key): mixed
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $this->assertInside($path);

        $stream = @fopen($path, 'rb');

        return $stream === false ? null : $stream;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function size(string $key): ?int
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $size = @filesize($path);

        return $size === false ? null : $size;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            $this->assertInside($path);
            if (!@unlink($path) && is_file($path)) {
                throw new StorageException('A stored file cannot be removed.');
            }
        }
    }

    /** Where a key lives: checked, and joined below the root. */
    private function path(string $key): string
    {
        StorageKey::assertValid($key);

        return $this->root . '/' . $key;
    }

    /**
     * Refuses a path that resolves outside the root. A valid key cannot
     * name one; a symlink planted inside the root could, and would turn a
     * write into an overwrite of whatever it points at.
     */
    private function assertInside(string $path): void
    {
        $real = realpath($path);
        if ($real === false || ($real !== $this->root && !str_starts_with($real, $this->root . DIRECTORY_SEPARATOR))) {
            throw new StorageException('A stored file resolves outside the storage root.');
        }
    }
}
