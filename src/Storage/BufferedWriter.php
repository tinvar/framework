<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * A StorageWriter that gathers what is written and hands it, whole, to the
 * store on commit: for a store that holds its files in memory anyway
 * (MemoryStorage, the testing fake). Nothing reaches the store before then.
 */
final class BufferedWriter implements StorageWriter
{
    private ?string $buffer = '';

    /** @param \Closure(string): void $commit puts the gathered bytes at the key */
    public function __construct(private readonly \Closure $commit) {}

    public function write(string $bytes): void
    {
        if ($this->buffer === null) {
            throw new StorageException('A stored file is written to after it was committed or given up.');
        }
        $this->buffer .= $bytes;
    }

    public function commit(): void
    {
        if ($this->buffer === null) {
            throw new StorageException('A stored file is committed after it was committed or given up.');
        }
        $bytes = $this->buffer;
        $this->buffer = null;
        ($this->commit)($bytes);
    }

    public function abort(): void
    {
        $this->buffer = null;
    }
}
