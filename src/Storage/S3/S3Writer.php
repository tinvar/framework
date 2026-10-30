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

    /** @var list<string> each part's ETag, in order */
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

            $parts = '';
            foreach ($this->etags as $index => $etag) {
                $parts .= '<Part><PartNumber>' . ($index + 1) . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
            }
            $response = $this->store->send('POST', $this->key, ['uploadId' => $uploadId], '<CompleteMultipartUpload>' . $parts . '</CompleteMultipartUpload>', timeout: 120.0);
            S3Storage::assertOk($response, 'stored');
            // S3 can answer 200 and put an error in the body of a completion
            // that failed after it began.
            if (str_contains($response->body, '<Error>')) {
                throw new StorageException('A stored file could not be stored: the object store failed the upload as it completed it.');
            }
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
                $this->store->send('DELETE', $this->key, ['uploadId' => $uploadId]);
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
        if ($this->uploadId === null) {
            $response = $this->store->send('POST', $this->key, ['uploads' => '']);
            S3Storage::assertOk($response, 'stored');
            if (preg_match('#<UploadId>([^<]{1,1024})</UploadId>#', $response->body, $m) !== 1) {
                throw new StorageException('The object store began an upload and named no upload id.');
            }
            $this->uploadId = html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES);
        }

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
        $this->etags[] = $etag;
    }
}
