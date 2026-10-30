<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * A file being written to storage as its bytes arrive, kept at its key only
 * once it is committed.
 *
 * For bytes that are pushed rather than read -- a download's body, handed
 * over chunk by chunk as the network delivers it (RemoteFiles) -- which
 * Storage::put(), reading a stream to its end, cannot take without the
 * whole file first landing somewhere else. Nothing is at the key until
 * commit(), and a writer given up on, or dropped without a commit, leaves
 * nothing behind: a reader sees the old file or the new one, never part of
 * either.
 */
interface StorageWriter
{
    /** @throws StorageException */
    public function write(string $bytes): void;

    /**
     * Puts what was written at the key, replacing whatever was there.
     *
     * @throws StorageException
     */
    public function commit(): void;

    /** Discards what was written. Nothing to discard is not a failure, nor is a second call. */
    public function abort(): void;
}
