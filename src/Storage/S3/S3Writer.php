<?php

declare(strict_types=1);

namespace Tinvar\Storage\S3;

use Tinvar\Storage\StorageException;
use Tinvar\Storage\StorageWriter;

/**
 * A file S3Storage is writing as its bytes arrive.
 *
 * Gathered in memory up to one part. A file that never fills one is a
 * single PUT on commit; one that does becomes a multipart upload, each part
 * sent as it fills, the upload completed on commit -- the moment the object
 * appears at its key, whole -- or aborted, so the store keeps none of its
 * parts. Dropped without a commit, it aborts.
 */
final class S3Writer implements StorageWriter
{
    private string $buffer = '';

    private ?string $uploadId = null;

    /** @var array<int, string> each part's ETag, by its number */
    private array $etags = [];

    private bool $done = false;

    public function __construct(private readonly S3Storage $store, private readonly string $key, private readonly int $partBytes) {}

    public function write(string $bytes): void
    {
        if ($this->done) {
            throw new StorageException('A stored file is written to after it was committed or given up.');
        }
        $this->buffer .= $bytes;

        try {
            while (\strlen($this->buffer) >= $this->partBytes) {
                $this->sendPart(substr($this->buffer, 0, $this->partBytes));
                $this->buffer = substr($this->buffer, $this->partBytes);
            }
        } catch (\Throwable $e) {
            $this->abort();

            throw $e;
        }
    }

    public function commit(): void
    {
        if ($this->done) {
            throw new StorageException('A stored file is committed after it was committed or given up.');
        }

        try {
            $uploadId = $this->uploadId;
            if ($uploadId === null) {
                S3Storage::assertOk($this->store->send('PUT', $this->key, body: $this->buffer, timeout: 120.0), 'stored');
                $this->buffer = '';
                $this->done = true;

                return;
            }

            if ($this->buffer !== '') {
                $this->sendPart($this->buffer);
                $this->buffer = '';
            }

            // The store's own completion, the one a direct upload's is: an
            // error in the body of a 200 among what it refuses.
            $this->store->completeMultipart($this->key, $uploadId, $this->etags);
            $this->uploadId = null;
            $this->done = true;
        } catch (\Throwable $e) {
            $this->abort();

            throw $e;
        }
    }

    public function abort(): void
    {
        $this->buffer = '';
        if ($this->done) {
            return;
        }
        $this->done = true;

        if ($this->uploadId !== null) {
            $uploadId = $this->uploadId;
            $this->uploadId = null;
            try {
                $this->store->abortMultipart($this->key, $uploadId);
            } catch (StorageException) {
                // An upload left begun keeps its parts until the bucket's
                // lifecycle rule removes them; nothing is at the key.
            }
        }
    }

    public function __destruct()
    {
        $this->abort();
    }

    private function sendPart(string $part): void
    {
        $this->uploadId ??= $this->store->beginMultipart($this->key);

        $number = \count($this->etags) + 1;
        if ($number > 10_000) {
            throw new StorageException('A stored file is larger than ten thousand parts.');
        }
        $response = $this->store->send('PUT', $this->key, ['partNumber' => (string) $number, 'uploadId' => $this->uploadId], $part, timeout: 120.0);
        S3Storage::assertOk($response, 'stored');
        $etag = $response->header('etag');
        if ($etag === null || $etag === '') {
            throw new StorageException('The object store took a part and named no ETag for it.');
        }
        $this->etags[$number] = $etag;
    }
}
