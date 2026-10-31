<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * A store that can add bytes to the end of what is at a key, for an upload
 * sent in chunks through PHP and resumed where it stopped (ADR 0143).
 *
 * Each append names the length it expects to find, and is refused unless
 * that is the length there, so a chunk sent twice -- retried after its
 * answer was lost -- is never written twice, and one sent ahead of a lost
 * one is never written out of place. Appends to one key are serialised: the
 * length is read, compared and written past under one lock.
 */
interface AppendableStorage extends Storage
{
    /**
     * Appends $bytes at $key -- which nothing there counts as empty -- if
     * what is there is exactly $offset bytes long. All of it or none of it:
     * a write that fails part way leaves the file at $offset.
     *
     * $guard, given, runs once the lock is held and the length is found to
     * match, before anything is written; it throwing writes nothing, and
     * its exception is the caller's. So a check that must not race the
     * append -- that the upload is still open -- is made under its lock.
     *
     * @param (\Closure(): void)|null $guard
     * @return int the length now there
     * @throws OffsetMismatch when what is there is not $offset bytes long
     * @throws StorageException when it could not be written
     * @throws \RuntimeException whatever $guard throws
     */
    public function append(string $key, int $offset, string $bytes, ?\Closure $guard = null): int;
}
