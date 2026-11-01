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
 * or the new one, never part of either. An append (ADR 0143) is the one
 * write made in place, under an exclusive lock on the file, and cut back to
 * where it began if it fails part way.
 */
final readonly class LocalStorage implements AppendableStorage, ListsKeys
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

    public function append(string $key, int $offset, string $bytes, ?\Closure $guard = null): int
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('An append is made at an offset of at least 0.');
        }
        $root = $this->madeRoot();
        StorageKey::assertValid($key);
        $path = $root . '/' . $key;
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new StorageException('A directory for a stored file cannot be made.');
        }
        $this->assertInside($root, $directory);
        if (is_link($path)) {
            throw new StorageException('A stored file resolves outside the storage root.');
        }
        // A file a waiting append's lock was taken on can be gone by then --
        // removed by an append before it whose guard refused a first chunk
        // -- and the append goes again, on the file at the path now.
        for ($attempt = 0; ; $attempt++) {
            $appended = $this->appendOnce($path, $offset, $bytes, $guard);
            if ($appended !== null) {
                return $appended;
            }
            if ($attempt >= 2) {
                throw new StorageException('A stored file kept changing under an append.');
            }
        }
    }

    /**
     * One try at append(): the length it then holds, or null when the file
     * it locked is no longer the one at the path.
     *
     * @param int<0, max> $offset
     */
    private function appendOnce(string $path, int $offset, string $bytes, ?\Closure $guard): ?int
    {
        // Nothing there is empty, and only an append at 0 begins a file:
        // one ahead of it is refused without leaving an empty file behind.
        clearstatcache(true, $path);
        $existed = is_file($path);
        if ($offset !== 0 && !$existed) {
            throw new OffsetMismatch(0);
        }

        $out = @fopen($path, 'cb');
        if ($out === false) {
            throw new StorageException('A stored file cannot be written.');
        }
        try {
            // The file opened is the one at the path, and no link: one
            // swapped in after the check above would point anywhere.
            $opened = fstat($out);
            $there = @lstat($path);
            if ($opened === false || $there === false || $opened['ino'] !== $there['ino'] || $opened['dev'] !== $there['dev']
                || ($there['mode'] & 0o170000) !== 0o100000) {
                throw new StorageException('A stored file resolves outside the storage root.');
            }
            @chmod($path, 0o600);
            if (!flock($out, LOCK_EX)) {
                throw new StorageException('A stored file cannot be locked to append to.');
            }
            try {
                clearstatcache(true, $path);
                $still = @lstat($path);
                if ($still === false || $still['ino'] !== $opened['ino'] || $still['dev'] !== $opened['dev']) {
                    return null;
                }
                $size = fstat($out)['size'] ?? -1;
                if ($size !== $offset) {
                    throw new OffsetMismatch(max(0, $size));
                }
                if ($guard !== null) {
                    try {
                        $guard();
                    } catch (\Throwable $e) {
                        // A first chunk refused leaves no empty file to
                        // say a session began, under the lock that would
                        // have written it.
                        if (!$existed && $size === 0) {
                            @unlink($path);
                        }

                        throw $e;
                    }
                }
                if (fseek($out, $offset) !== 0) {
                    throw new StorageException('A stored file cannot be appended to.');
                }
                try {
                    $written = 0;
                    $length = \strlen($bytes);
                    while ($written < $length) {
                        $wrote = @fwrite($out, substr($bytes, $written, 1_048_576));
                        if ($wrote === false || $wrote === 0) {
                            throw new StorageException('A stored file could not be appended to the end.');
                        }
                        $written += $wrote;
                    }
                    if (!@fflush($out)) {
                        throw new StorageException('A stored file could not be appended to the end.');
                    }
                } catch (\Throwable $e) {
                    // Cut back to where it began, whatever threw -- an
                    // error handler's exception from a write among it:
                    // half a chunk is no chunk.
                    if (!@ftruncate($out, $offset)) {
                        throw new StorageException('A stored file could not be appended to the end, nor cut back to where the append began.', 0, $e);
                    }

                    throw $e instanceof StorageException ? $e : new StorageException('A stored file could not be appended to the end.', 0, $e);
                }

                return $offset + $length;
            } finally {
                flock($out, LOCK_UN);
            }
        } finally {
            fclose($out);
        }
    }

    /**
     * The files under $prefix and when each was last written, never
     * following a link: for pruning to find what no record names.
     */
    public function keysUnder(string $prefix, int $limit, ?int $writtenAtOrBefore = null): array
    {
        if (!StorageKey::isValidPrefix($prefix)) {
            throw new \InvalidArgumentException("'{$prefix}' is not a storage prefix to list.");
        }
        $root = realpath($this->root);
        if ($root === false || !is_dir("{$root}/{$prefix}") || is_link("{$root}/{$prefix}")) {
            return [];
        }

        $keys = [];
        $pending = [$prefix];
        while ($pending !== [] && \count($keys) < $limit) {
            $directory = array_shift($pending);
            $names = @scandir("{$root}/{$directory}");
            if ($names === false) {
                throw new StorageException('A directory of stored files cannot be listed.');
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..' || \count($keys) >= $limit) {
                    continue;
                }
                $key = "{$directory}/{$name}";
                $path = "{$root}/{$key}";
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    $pending[] = $key;
                } elseif (StorageKey::isValid($key)) {
                    $at = @filemtime($path);
                    if ($at !== false && ($writtenAtOrBefore === null || $at <= $writtenAtOrBefore)) {
                        $keys[$key] = $at;
                    }
                }
            }
        }

        return $keys;
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
