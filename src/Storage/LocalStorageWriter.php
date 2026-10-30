<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * A file LocalStorage is writing as its bytes arrive: a temporary file
 * beside its destination, inside the store's root, renamed into place on
 * commit -- so the bytes go to the disk they are kept on, once, and never
 * through the system's temporary directory first.
 *
 * Made only by LocalStorage::writer(), which has checked the directory is
 * inside the root and created the temporary file exclusively, 0600.
 * Dropped without a commit -- a transfer that threw, a digest that did not
 * match -- it removes its temporary file.
 */
final class LocalStorageWriter implements StorageWriter
{
    /** @var resource|null */
    private mixed $out;

    /** @param resource $out the temporary file, open for writing */
    public function __construct(mixed $out, private readonly string $temporary, private readonly string $path)
    {
        $this->out = $out;
    }

    public function write(string $bytes): void
    {
        if ($this->out === null) {
            throw new StorageException('A stored file is written to after it was committed or given up.');
        }
        if ($bytes !== '' && fwrite($this->out, $bytes) !== \strlen($bytes)) {
            $this->abort();

            throw new StorageException('A stored file could not be written.');
        }
    }

    public function commit(): void
    {
        if ($this->out === null) {
            throw new StorageException('A stored file is committed after it was committed or given up.');
        }

        $flushed = fflush($this->out);
        fclose($this->out);
        $this->out = null;
        if (!$flushed || !@rename($this->temporary, $this->path)) {
            @unlink($this->temporary);

            throw new StorageException('A stored file cannot be moved into place.');
        }
    }

    public function abort(): void
    {
        if ($this->out === null) {
            return;
        }
        fclose($this->out);
        $this->out = null;
        @unlink($this->temporary);
    }

    public function __destruct()
    {
        $this->abort();
    }
}
