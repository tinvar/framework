<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * A store reached over HTTP -- S3, or anything speaking its API -- which can
 * do what a disk cannot: hand a browser a URL to fetch a file from, or send
 * one to, signed for seconds, so the bytes need not pass through PHP.
 *
 * Every URL is for one key the framework made, one method, and a short
 * while; an upload's is for one size. The credentials that sign them stay
 * with PHP (S3\S3Credentials).
 */
interface ObjectStore extends Storage
{
    /**
     * Hands what is at $key to $sink as it arrives, chunk by chunk, never
     * gathering it first: for sending a file on to a client.
     *
     * @param \Closure(string): void $sink
     * @return bool false when nothing is at $key; nothing was handed over then
     * @throws StorageException
     */
    public function readTo(string $key, \Closure $sink): bool;

    /**
     * A URL that fetches what is at $key until $ttl seconds from now, its
     * response carrying the headers given -- `content-type`,
     * `content-disposition`, `cache-control` -- whatever the store kept.
     * Always a type and a disposition: application/octet-stream as an
     * attachment unless given, a type uploads take, and inline only for an
     * image.
     *
     * @param array<string, string> $responseHeaders lowercased name => value
     */
    public function presignedGet(string $key, int $ttl, array $responseHeaders = []): string;

    /**
     * A URL a browser PUTs a file of exactly $size bytes to, at $key, until
     * $ttl seconds from now, with the headers PresignedUpload names: the
     * size and those are signed, and the store refuses any other.
     */
    public function presignedPut(string $key, int $size, int $ttl): PresignedUpload;
}
