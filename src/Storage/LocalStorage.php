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
 * Made when the first file is written, not when the store is built: an
 * application builds its store on every boot, and most boots -- a CLI
 * command, a test, a worker that never sees an upload -- write nothing.
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

        $this->root = rtrim($root, '/\\');
    }

    public function put(string $key, mixed $stream): void
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('Storage::put() takes a readable stream.');
        }

        $writer = $this->writer($key);
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false) {
                    throw new StorageException('A stored file could not be written to the end.');
                }
                $writer->write($chunk);
            }
            $writer->commit();
        } finally {
            $writer->abort();
        }
    }

    /**
     * A temporary file beside the destination, inside the root, renamed into
     * place on commit (LocalStorageWriter).
     */
    public function writer(string $key): StorageWriter
    {
        $root = $this->madeRoot();
        StorageKey::assertValid($key);
        $path = $root . '/' . $key;
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new StorageException('A directory for a stored file cannot be made.');
        }
        $this->assertInside($root, $directory);

        // Created exclusively, under a name nobody else can be using, so a
        // file planted at the temporary name is never written through.
        $temporary = $directory . '/.tmp-' . bin2hex(random_bytes(8));
        $out = @fopen($temporary, 'xb');
        if ($out === false) {
            throw new StorageException('A stored file cannot be written.');
        }
        @chmod($temporary, 0o600);

        return new LocalStorageWriter($out, $temporary, $path);
    }

    public function read(string $key): mixed
    {
        $path = $this->existing($key);
        if ($path === null) {
            return null;
        }

        $stream = @fopen($path, 'rb');

        return $stream === false ? null : $stream;
    }

    public function exists(string $key): bool
    {
        return $this->existing($key) !== null;
    }

    public function size(string $key): ?int
    {
        $path = $this->existing($key);
        if ($path === null) {
            return null;
        }

        $size = @filesize($path);

        return $size === false ? null : $size;
    }

    public function delete(string $key): void
    {
        $path = $this->existing($key);
        if ($path !== null && !@unlink($path) && is_file($path)) {
            throw new StorageException('A stored file cannot be removed.');
        }
    }

    /**
     * Where on disk the file at $key is, checked to be inside the root;
     * null when nothing is there. For a server that sends the file itself
     * (Response::withFile()), never for a client to see.
     */
    public function path(string $key): ?string
    {
        return $this->existing($key);
    }

    /**
     * The file a key names, checked to be inside the root; null when there
     * is none -- a root not made yet holds nothing.
     */
    private function existing(string $key): ?string
    {
        StorageKey::assertValid($key);

        $root = realpath($this->root);
        if ($root === false) {
            return null;
        }

        $path = $root . '/' . $key;
        if (!is_file($path)) {
            return null;
        }
        $this->assertInside($root, $path);

        return $path;
    }

    /** The root, made 0700 if it is not there yet, as its real path. */
    private function madeRoot(): string
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0o700, true) && !is_dir($this->root)) {
            throw new StorageException("The storage root '{$this->root}' does not exist and cannot be made.");
        }

        $real = realpath($this->root);
        if ($real === false) {
            throw new StorageException("The storage root '{$this->root}' cannot be resolved.");
        }

        return $real;
    }

    /**
     * Refuses a path that resolves outside the root. A valid key cannot
     * name one; a symlink planted inside the root could, and would turn a
     * write into an overwrite of whatever it points at.
     */
    private function assertInside(string $root, string $path): void
    {
        $real = realpath($path);
        if ($real === false || ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR))) {
            throw new StorageException('A stored file resolves outside the storage root.');
        }
    }
}
