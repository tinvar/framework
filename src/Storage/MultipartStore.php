<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * An object store a browser can send one file to in parts, over as many
 * sittings as it takes: a multipart upload PHP begins, each part a URL
 * presigned for its number, its exact length and a few minutes, and the
 * parts the store holds asked of it whenever the browser comes back -- so
 * an upload interrupted by a refresh, a crash or a dropped network goes on
 * from the last part the store took (ADR 0143).
 *
 * Nothing is at the key until PHP completes the upload; until then the
 * parts are the store's alone, and aborting the upload removes them.
 */
interface MultipartStore extends ObjectStore
{
    /**
     * Begins a multipart upload at $key.
     *
     * @return string the store's id for it
     * @throws StorageException
     */
    public function beginMultipart(string $key): string;

    /**
     * A URL a browser PUTs part $number of the upload to -- exactly $size
     * bytes of it -- until $ttl seconds from now. The number, the upload and
     * the length are signed; the store refuses any other.
     */
    public function presignedPart(string $key, string $uploadId, int $number, int $size, int $ttl): PresignedUpload;

    /**
     * The parts the store holds of the upload, by number; null when it has
     * no such upload -- completed, aborted, or never begun.
     *
     * @return array<int, array{size: int, etag: string}>|null
     * @throws StorageException
     */
    public function listParts(string $key, string $uploadId): ?array;

    /**
     * Completes the upload from the parts named, in order: what is at $key
     * from now on is them, joined.
     *
     * @param array<int, string> $etags part number => the ETag the store gave it
     * @throws StorageException
     */
    public function completeMultipart(string $key, string $uploadId, array $etags): void;

    /** Aborts the upload, the store dropping every part of it. Nothing there is not a failure. */
    public function abortMultipart(string $key, string $uploadId): void;

    /**
     * Up to $limit uploads begun and neither completed nor aborted whose key
     * starts with $prefix -- a prefix and a slash, or one whole key -- each
     * with when it was begun, in seconds since the epoch.
     *
     * @return list<array{key: string, uploadId: string, initiated: int}>
     * @throws StorageException when the store could not be asked
     */
    public function multipartUploadsUnder(string $prefix, int $limit): array;
}
