<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * Where uploaded files are kept, by key.
 *
 * tinvar-spec.md: "Storage keys are server-generated; client filenames are
 * metadata only." So a key is never anything a client wrote: every
 * implementation refuses one that is not shaped as the framework makes them
 * (StorageKey), which leaves no way to spell a path out of the store even
 * for code that forgot where its key came from.
 *
 * Streams in and out, never strings: an upload can be larger than anything
 * a worker should hold in memory at once.
 */
interface Storage
{
    /**
     * Writes what $stream holds at $key, replacing whatever was there -- all
     * of it or none of it: a reader never sees half a file.
     *
     * @param resource $stream readable, from its current position to its end
     * @throws StorageException
     */
    public function put(string $key, mixed $stream): void;

    /**
     * What is at $key, as a stream the caller closes; null when nothing is.
     *
     * @return resource|null
     */
    public function read(string $key): mixed;

    public function exists(string $key): bool;

    /** The size in bytes of what is at $key, or null when nothing is. */
    public function size(string $key): ?int;

    /** Removes what is at $key. Nothing there is not a failure. */
    public function delete(string $key): void;
}
