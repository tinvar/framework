<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Attributes\Uploaded;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Render\TokenError;
use Tinvar\Storage\AppendableStorage;
use Tinvar\Storage\MultipartStore;
use Tinvar\Storage\OffsetMismatch;
use Tinvar\Storage\S3\S3Storage;
use Tinvar\Storage\StorageException;
use Tinvar\Storage\StorageKey;

/**
 * POST /_upload/session and the paths under it: an upload that survives a
 * refresh, a closed tab, a crash and a dropped network, going on from the
 * byte the server holds rather than from the first (ADR 0143).
 *
 * Behind the stack /_upload is behind -- the session, CSRF, a throttle,
 * authentication -- and inside the action's own context, every step held
 * to the binding the ticket carried, as /_upload holds a ticket to it:
 *
 *   1. /_upload/session takes the ticket, and the size, name and type of
 *      the file, declared up front. The size is held to the declared limit,
 *      and recorded as a waiting upload -- of no type, so nothing could ever
 *      be handed it -- counted against the cap on waiting uploads and the
 *      user's quota for the whole of it, from the start. Its bytes wait under
 *      PARTIAL_PREFIX. The answer is a session (UploadSession), signed and
 *      bound as the ticket was, good for SESSION_TTL.
 *   2. Through PHP, in chunks: /_upload/session/chunk appends one at the
 *      offset it names, which must be exactly the length held -- or it is a
 *      409 naming that length -- under a lock on the bytes, the session's
 *      record checked open under the same lock. Between chunks of at least
 *      MIN_CHUNK_BYTES and at most MAX_CHUNK_BYTES, each optionally carrying
 *      its SHA-256.
 *   3. Straight to an object store, in parts: a multipart upload PHP began,
 *      /_upload/session/parts asking the store which parts it holds and
 *      handing out URLs for the next few, each presigned for its number,
 *      its exact length and PART_TTL.
 *   4. /_upload/session/offset says how much is in, whenever the browser
 *      comes back: the length appended, or the parts the store holds in
 *      order. Never trusted from the browser.
 *   5. Once the bytes held are the size declared -- the last chunk, or
 *      /_upload/session/complete -- the session is claimed, once, and the
 *      whole file kept exactly as /_upload keeps one: sniffed, drawn again,
 *      scanned, counted and stored under a key of the server's. Nothing of
 *      it is trusted, served or bound before. Once kept, or refused for good
 *      (a 4xx: its type, its size), its partial bytes go, and its record;
 *      failed for a reason that passes -- a scanner or a store that did not
 *      answer, bytes held the server could not read back, a 5xx such as the
 *      507 of a quota with no room left -- its claim is given back and its
 *      bytes kept, and the last chunk sent again, or /complete, completes
 *      it from them. While a
 *      completion runs, any other use of the session is a 425,
 *      `upload_completing`, with Retry-After -- an abort too, since the
 *      file may yet be kept. A claim holds for SESSION_CLAIM_LEASE
 *      (UploadStore): one older was left by a process that died while
 *      keeping, and the session is open again, to be completed, aborted
 *      or pruned. Refused with a 429 -- too many of the user's uploads
 *      waiting -- the claim is given back too. The upload made from it is
 *      found from the session again, so a completion whose answer was lost
 *      is answered once more, never made twice.
 *   6. /_upload/session/abort gives it up: its bytes removed -- the store's
 *      upload aborted -- and its room given back; a 425 while a
 *      completion holds it.
 *
 * A session gone -- expired and pruned, abandoned, or completed and claimed
 * -- is a 404, `upload_session_gone`, and one bound to another session,
 * user, epoch, tenant or panel a 403, as a ticket's is: either way the
 * browser forgets it.
 */
final readonly class ResumableUploads
{
    public function __construct(
        private UploadEndpoint $endpoint,
        private Uploads $uploads,
        private AuthContext $auth,
    ) {}

    public function handle(Request $request, string $rawBody): Response
    {
        if ($request->path === UploadEndpoint::SESSION_PATH) {
            return $this->begin($request, $rawBody);
        }

        $raw = $request->path === UploadEndpoint::SESSION_CHUNK_PATH
            ? $request->header(UploadEndpoint::SESSION_HEADER)
            : (UploadEndpoint::jsonBody($request, $rawBody)['session'] ?? null);
        if (!\is_string($raw) || $raw === '' || \strlen($raw) > UploadEndpoint::MAX_TICKET_BYTES) {
            throw new HttpException(400, 'Send the upload session /_upload/session answered with.', 'bad_request');
        }
        try {
            $session = UploadSession::decode($raw, $this->uploads->ticketSigner, $this->uploads->now());
        } catch (TokenError $e) {
            throw UploadEndpoint::refused("session {$e->getMessage()}");
        }
        $binding = $session->binding();
        [$action, $declared] = $this->endpoint->declarationFor($binding);

        return $this->endpoint->within($action, $request, function (Request $inner) use ($session, $binding, $declared, $rawBody): Response {
            $this->endpoint->assertBinding($inner, $binding);

            return match ($inner->path) {
                UploadEndpoint::SESSION_OFFSET_PATH => $this->offset($session),
                UploadEndpoint::SESSION_CHUNK_PATH => $this->chunk($inner, $rawBody, $session, $declared),
                UploadEndpoint::SESSION_PARTS_PATH => $this->parts($session),
                UploadEndpoint::SESSION_COMPLETE_PATH => $this->complete($session, $declared),
                UploadEndpoint::SESSION_ABORT_PATH => $this->abort($session),
                default => throw new HttpException(404, 'No such upload path.', 'not_found'),
            };
        });
    }

    private function begin(Request $request, string $rawBody): Response
    {
        $body = UploadEndpoint::jsonBody($request, $rawBody);
        $raw = $body[UploadEndpoint::TICKET_FIELD] ?? null;
        if (!\is_string($raw) || $raw === '' || \strlen($raw) > UploadEndpoint::MAX_TICKET_BYTES) {
            throw new HttpException(400, 'Send the upload ticket in the `ticket` field.', 'bad_request');
        }
        try {
            $ticket = UploadTicket::decode($raw, $this->uploads->ticketSigner, $this->uploads->now());
        } catch (TokenError $e) {
            throw UploadEndpoint::refused($e->getMessage());
        }
        [$action, $declared] = $this->endpoint->declarationFor($ticket);

        return $this->endpoint->within($action, $request, function (Request $inner) use ($ticket, $declared, $body): Response {
            $this->endpoint->assertBinding($inner, $ticket);

            return $this->open($ticket, $declared, $body);
        });
    }

    /** @param array<mixed> $body */
    private function open(UploadTicket $ticket, Uploaded $declared, array $body): Response
    {
        $size = $body['size'] ?? null;
        if (!\is_int($size) || $size < 0) {
            throw new HttpException(400, 'Send the size of the file in bytes, as `size`.', 'bad_request');
        }
        if ($size === 0) {
            throw new HttpException(422, 'The file is empty.', 'upload_empty');
        }
        if ($size > min($declared->maxBytes, $this->uploads->maxBytes)) {
            throw UploadEndpoint::tooLarge();
        }
        $direct = ($body['direct'] ?? false) === true;
        $storage = $this->uploads->storage;
        if ($direct ? !$storage instanceof MultipartStore : !$storage instanceof AppendableStorage) {
            // The client sends the file whole instead, as it would have.
            throw new HttpException(404, $direct ? 'Files here are not sent to the store in parts.' : 'Files here are not sent through PHP in chunks.', 'not_found');
        }

        $user = (string) $this->auth->id();
        $now = $this->uploads->now();
        $owner = $this->uploads->ownerHash($user);
        $quota = $this->uploads->quota?->bytesFor($user);
        // The client's name is metadata, cleaned; its type for the file is
        // asked only to be ignored -- the bytes say what it is, once whole.
        $record = new PendingUpload(
            id: bin2hex(random_bytes(16)),
            key: StorageKey::generate(UploadEndpoint::PARTIAL_PREFIX, ''),
            action: $ticket->action,
            field: $ticket->field,
            targetClass: $ticket->targetClass,
            targetId: $ticket->targetId,
            prefix: $ticket->prefix,
            tenant: $ticket->tenant,
            panel: $ticket->panel,
            sessionHash: $ticket->sessionHash,
            authHash: $ticket->authHash,
            name: UploadName::clean(\is_string($body['name'] ?? null) ? $body['name'] : ''),
            // No type until PHP has sniffed the whole file: a session is
            // never an upload an action could be handed (UploadBinder).
            type: '',
            size: $size,
            createdAt: $now,
            expiresAt: $now + UploadEndpoint::SESSION_TTL,
            ownerHash: $owner,
        );
        if (!$this->uploads->store->addWithin($record, UploadEndpoint::MAX_PENDING, $now, $quota)) {
            throw $quota !== null && $this->uploads->store->bytesHeld($owner, $now) + $size > $quota
                ? UploadEndpoint::overQuota()
                : UploadEndpoint::tooManyPending();
        }

        $uploadId = '';
        $partBytes = 0;
        if ($direct) {
            try {
                $uploadId = $storage->beginMultipart($record->key);
            } catch (\Throwable $e) {
                $this->uploads->store->forget($record->id);

                throw $e;
            }
            $partBytes = self::partBytesFor($size);
        }

        $session = UploadSession::under(
            $ticket,
            $record->id,
            $direct ? UploadSession::PARTS : UploadSession::CHUNKS,
            $size,
            $now,
            UploadEndpoint::SESSION_TTL,
            $uploadId,
            $partBytes,
        );

        return self::json(201, [
            'session' => $session->encode($this->uploads->ticketSigner),
            'mode' => $session->mode,
            'size' => $size,
            'offset' => 0,
            ...($direct ? ['partBytes' => $partBytes] : ['chunkBytes' => UploadEndpoint::CHUNK_BYTES]),
            'expiresAt' => $session->expiresAt,
        ]);
    }

    private function offset(UploadSession $session): Response
    {
        $record = $this->openRecord($session);
        if ($record === null) {
            $kept = $this->completed($session);

            return self::json(200, [
                'mode' => $session->mode,
                'size' => $session->size,
                'offset' => $session->size,
                'upload' => self::described($kept),
            ]);
        }

        $offset = $session->mode === UploadSession::CHUNKS
            ? $this->appendable()->size($record->key) ?? 0
            : $this->heldOffset($session, $record);

        return self::json(200, [
            'mode' => $session->mode,
            'size' => $session->size,
            'offset' => $offset,
            'name' => $record->name,
            ...($session->mode === UploadSession::PARTS ? ['partBytes' => $session->partBytes] : ['chunkBytes' => UploadEndpoint::CHUNK_BYTES]),
            'expiresAt' => $record->expiresAt,
        ]);
    }

    private function chunk(Request $request, string $bytes, UploadSession $session, Uploaded $declared): Response
    {
        if ($session->mode !== UploadSession::CHUNKS) {
            throw new HttpException(400, 'This upload is sent to the store in parts.', 'bad_request');
        }
        $header = $request->header(UploadEndpoint::OFFSET_HEADER) ?? '';
        if (!ctype_digit($header) || \strlen($header) > 15) {
            throw new HttpException(400, 'Send the offset the chunk is for, in Upload-Offset.', 'bad_request');
        }
        $offset = (int) $header;
        $length = \strlen($bytes);
        $declaredLength = $request->header('content-length');
        if ($length === 0 || ($declaredLength !== null && $declaredLength !== (string) $length)) {
            // Nothing, or less than was said: a body cut short is never appended.
            throw new HttpException(400, 'Send the chunk whole, as the body.', 'bad_request');
        }
        if ($length > UploadEndpoint::MAX_CHUNK_BYTES || $offset + $length > $session->size) {
            throw UploadEndpoint::tooLarge();
        }
        $final = $offset + $length === $session->size;
        if (!$final && $length < UploadEndpoint::MIN_CHUNK_BYTES) {
            throw new HttpException(400, 'A chunk short of the end is at least ' . UploadEndpoint::MIN_CHUNK_BYTES . ' bytes.', 'upload_chunk_small');
        }
        $checksum = $request->header(UploadEndpoint::CHECKSUM_HEADER);
        if ($checksum !== null) {
            if (preg_match('#^sha256 ([A-Za-z0-9+/]{43}=)$#', trim($checksum), $m) !== 1) {
                throw new HttpException(400, 'A chunk\'s checksum is `sha256 <base64>`.', 'upload_checksum_algorithm');
            }
            if (!hash_equals((string) base64_decode($m[1], true), hash('sha256', $bytes, true))) {
                // tus's own: the chunk was changed on its way, and is not written.
                throw new HttpException(460, 'The chunk does not match its checksum.', 'upload_checksum');
            }
        }

        $record = $this->openRecord($session);
        if ($record === null) {
            return UploadEndpoint::kept($this->completed($session));
        }

        $storage = $this->appendable();
        $now = $this->uploads->now();
        $claimed = false;
        try {
            $held = $storage->append($record->key, $offset, $bytes, function () use ($session, $final, $now, &$claimed): void {
                // Under the lock on the bytes: no chunk is written to a
                // session that has been completed, abandoned or has run out
                // since it was looked at, and of two last chunks one goes on.
                if ($this->openRecord($session) === null || ($final && !$this->uploads->store->claim($session->id, $now))) {
                    throw new SessionClosed();
                }
                $claimed = $final;
            });
        } catch (OffsetMismatch $e) {
            // Whole already: the last chunk sent again after a keep that
            // failed for a reason that passes gave its claim back. Kept from
            // the bytes held; this chunk's are not written.
            if ($final && $e->actual === $session->size) {
                return $this->completeHeld($session, $record, $declared);
            }

            throw self::elsewhere($e->actual);
        } catch (SessionClosed) {
            return UploadEndpoint::kept($this->completed($session));
        } catch (\Throwable $e) {
            if ($claimed) {
                $this->uploads->store->release($session->id, $now);
            }

            throw $e;
        }

        if (!$final) {
            return self::json(200, ['offset' => $held], [UploadEndpoint::OFFSET_HEADER => (string) $held]);
        }

        return $this->keepWhole($session, $record, $declared, $this->fetchAppended($record, $session), $now);
    }

    /**
     * A session sent in chunks that holds every byte, completed: claimed,
     * once, and kept from the bytes held -- after a keep that failed for a
     * reason that passes, through its last chunk sent again or /complete.
     */
    private function completeHeld(UploadSession $session, PendingUpload $record, Uploaded $declared): Response
    {
        $claimedAt = $this->uploads->now();
        if (!$this->uploads->store->claim($session->id, $claimedAt)) {
            return UploadEndpoint::kept($this->completed($session));
        }
        try {
            $held = $this->appendable()->size($record->key) ?? 0;
        } catch (\Throwable $e) {
            $this->uploads->store->release($session->id, $claimedAt);

            throw $e;
        }
        if ($held !== $session->size) {
            $this->uploads->store->release($session->id, $claimedAt);

            throw self::elsewhere($held);
        }

        return $this->keepWhole($session, $record, $declared, $this->fetchAppended($record, $session), $claimedAt);
    }

    /** @return \Closure(string): void what fetches the bytes appended into the path it is given */
    private function fetchAppended(PendingUpload $record, UploadSession $session): \Closure
    {
        $storage = $this->appendable();

        return static function (string $path) use ($storage, $record, $session): void {
            $in = $storage->read($record->key);
            if ($in === null) {
                // The length was just found whole at this key: a store that
                // cannot open it now -- out of descriptors, a permission
                // blinked -- is the server's fault, a 5xx that gives the claim
                // back and keeps the bytes, never a 4xx that ends the session.
                throw new StorageException('A partial upload held whole could not be opened.');
            }
            try {
                self::copy($in, $path, $session->size);
            } finally {
                fclose($in);
            }
        };
    }

    private function parts(UploadSession $session): Response
    {
        if ($session->mode !== UploadSession::PARTS) {
            throw new HttpException(400, 'This upload is sent through PHP in chunks.', 'bad_request');
        }
        $record = $this->openRecord($session);
        if ($record === null) {
            // Being completed, or over: a 425 to come back, or the 404.
            $this->completed($session);

            throw self::gone();
        }
        $store = $this->multipart();
        $held = $this->heldParts($session, $record);

        // From the first part not held as it was granted, the next few.
        $granted = [];
        for ($number = 1; $number <= $session->partCount() && \count($granted) < UploadEndpoint::PARTS_AHEAD; $number++) {
            if (($held[$number]['size'] ?? -1) === $session->partSize($number)) {
                continue;
            }
            $part = $store->presignedPart($record->key, $session->uploadId, $number, $session->partSize($number), UploadEndpoint::PART_TTL);
            $granted[] = ['number' => $number, 'size' => $session->partSize($number), ...$part->jsonSerialize()];
        }

        return self::json(200, [
            'mode' => $session->mode,
            'size' => $session->size,
            'offset' => self::contiguous($session, $held),
            'partBytes' => $session->partBytes,
            'expiresAt' => $record->expiresAt,
            'parts' => $granted,
        ]);
    }

    private function complete(UploadSession $session, Uploaded $declared): Response
    {
        if ($session->mode !== UploadSession::PARTS) {
            // Completed by its last chunk -- or, once it holds every byte
            // and a keep failed for a reason that passes, here.
            $record = $this->openRecord($session);
            if ($record === null) {
                return UploadEndpoint::kept($this->completed($session));
            }
            $held = $this->appendable()->size($record->key) ?? 0;
            if ($held !== $session->size) {
                throw self::elsewhere($held);
            }

            return $this->completeHeld($session, $record, $declared);
        }
        $record = $this->openRecord($session);
        $now = $this->uploads->now();
        // Once: of two completions, one goes on.
        if ($record === null || !$this->uploads->store->claim($session->id, $now)) {
            return UploadEndpoint::kept($this->completed($session));
        }

        $store = $this->multipart();
        try {
            // Assembled already, by a completion whose keep failed for a
            // reason that passes: kept from the object it made.
            if (!$this->assembled($session, $record)) {
                $held = $this->heldParts($session, $record);
                $offset = self::contiguous($session, $held);
                if ($offset !== $session->size) {
                    throw self::elsewhere($offset);
                }
                $etags = [];
                foreach ($held as $number => $part) {
                    if ($number <= $session->partCount()) {
                        $etags[$number] = $part['etag'];
                    }
                }
                $store->completeMultipart($record->key, $session->uploadId, $etags);
            }
        } catch (\Throwable $e) {
            // Not complete: the session is open again, to be gone on with.
            $this->uploads->store->release($session->id, $now);

            throw $e;
        }

        return $this->keepWhole($session, $record, $declared, function (string $path) use ($store, $record, $session): void {
            $out = fopen($path, 'wb');
            if ($out === false) {
                throw new \RuntimeException('No temporary file to fetch an upload into.');
            }
            $bytes = 0;
            try {
                // No more than declared: the store held each part to its
                // signed length, and this holds the read to the whole.
                $found = $store->readTo($record->key, static function (string $chunk) use ($out, $session, &$bytes): void {
                    $bytes += \strlen($chunk);
                    if ($bytes > $session->size) {
                        throw UploadEndpoint::tooLarge();
                    }
                    // A temporary disk full is the server's fault: a 5xx, the parts kept.
                    UploadEndpoint::writeWhole($out, $chunk);
                });
            } finally {
                fclose($out);
            }
            if (!$found) {
                // Assembled at this key a moment ago, or found there whole:
                // gone from under the read is the store's fault, a 5xx that
                // keeps the session -- the next try finds out whether the
                // store still has it, and ends the session if not.
                throw new StorageException('An upload assembled in the store could not be read.');
            }
        }, $now);
    }

    private function abort(UploadSession $session): Response
    {
        $record = $this->uploads->store->find($session->id);
        $now = $this->uploads->now();
        if ($record !== null && $session->names($record) && $this->claimHeld($record, $now)) {
            // A completion is keeping the file: it may yet be kept, so the
            // abort is not done, and the browser is told to come back.
            throw self::completing();
        }
        if ($record !== null && $session->names($record) && $this->uploads->store->claim($session->id, $now)) {
            try {
                $this->removeBytes($session, $record);
            } catch (\Throwable $e) {
                // Kept, for pruning to remove once it runs out.
                $this->uploads->store->release($session->id, $now);

                throw $e;
            }
            $this->uploads->store->forget($session->id, $now);
        }

        return Response::make(204, '', ['cache-control' => 'private, no-store', 'vary' => 'Cookie']);
    }

    /**
     * The whole file, claimed: fetched into a temporary file by $fetch and
     * kept as /_upload keeps a file. The session's partial bytes and its
     * record are held until that has an outcome: kept, or refused for good
     * -- not a type the field takes, too large -- and they go, the session
     * over; failed for a reason that passes -- a scanner or a store that did
     * not answer, a 5xx (507 over the quota too), a 429 -- and its claim is
     * given back, its bytes still there, for the next try to complete it.
     *
     * The claim is fenced by $claimedAt, the time it was made: a keep that
     * outlived its lease (UploadStore::SESSION_CLAIM_LEASE) may have had it
     * taken over, and then gives back, renews and forgets nothing -- the
     * session is the other request's. The lease is renewed once the file is
     * fetched, the longest step; a keep that finds its claim taken over then
     * stops, a 425. And the upload made has an id drawn from the session's
     * (resultId()), so of two keeps that both get that far, one adds it: the
     * other finds it made and ends the session, or is refused adding it.
     *
     * @param \Closure(string): void $fetch writes the whole file to the path it is given
     */
    private function keepWhole(UploadSession $session, PendingUpload $record, Uploaded $declared, \Closure $fetch, int $claimedAt): Response
    {
        $resultId = self::resultId($this->uploads, $session->id);
        $made = $this->uploads->store->find($resultId);
        if ($made !== null && $made->type !== '') {
            // Kept already, by a keep whose claim was taken over before it ended the session.
            $this->end($session, $record, $claimedAt);

            return UploadEndpoint::kept($made);
        }

        $path = tempnam(sys_get_temp_dir(), 'tinvar-resumed-');
        if ($path === false) {
            $this->uploads->store->release($session->id, $claimedAt);

            throw new \RuntimeException('No temporary file to gather an upload into.');
        }

        try {
            try {
                $fetch($path);
                clearstatcache(true, $path);
                if (filesize($path) !== $session->size) {
                    // Every byte was found held before it was fetched: a
                    // temporary file short of them is the server's fault, a
                    // 5xx that keeps the session, not a refusal that ends it.
                    throw new StorageException('An upload was not fetched whole into a temporary file.');
                }
                $renewed = $this->uploads->now();
                if (!$this->uploads->store->renew($session->id, $claimedAt, $renewed)) {
                    // Taken over: the session is another request's now.
                    throw new ClaimLost();
                }
                $claimedAt = $renewed;
                $kept = $this->endpoint->keepFile($session->binding(), $declared, $path, $record->name, $resultId, $record);
            } catch (ClaimLost) {
                throw self::completing();
            } catch (\Throwable $e) {
                $made = $this->uploads->store->find($resultId);
                if ($made !== null && $made->type !== '') {
                    // Kept meanwhile by a request that took the claim over,
                    // and this one refused adding it again: that upload.
                    $this->end($session, $record, $claimedAt);

                    return UploadEndpoint::kept($made);
                }
                // Refused for good: a 4xx -- but for 429, too many of the
                // user's uploads waiting, which passes as they are claimed.
                if ($e instanceof HttpException && $e->status >= 400 && $e->status < 500 && $e->status !== 429) {
                    $this->end($session, $record, $claimedAt);
                } else {
                    $this->uploads->store->release($session->id, $claimedAt);
                }

                throw $e;
            }
            $this->end($session, $record, $claimedAt);

            return UploadEndpoint::kept($kept);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A claimed session over: its record forgotten, if the claim made at
     * $claimedAt still holds it, and then its bytes removed, where they can
     * be. A claim taken over leaves both to the request that holds it.
     */
    private function end(UploadSession $session, PendingUpload $record, int $claimedAt): void
    {
        if (!$this->uploads->store->forget($session->id, $claimedAt)) {
            return;
        }
        try {
            $this->removeBytes($session, $record);
        } catch (StorageException) {
            // Removed by pruning's sweep of the prefix, or the bucket's lifecycle rule.
        }
    }

    /** What is held of an upload, removed: the partial file, or the store's upload and whatever it completed to. */
    private function removeBytes(UploadSession $session, PendingUpload $record): void
    {
        if ($session->mode === UploadSession::PARTS) {
            $this->multipart()->abortMultipart($record->key, $session->uploadId);
        }
        $this->uploads->storage->delete($record->key);
    }

    /**
     * The session's record, if it is still open: there, as the session began
     * it, unexpired, and unclaimed -- or claimed longer ago than the lease,
     * by a completion whose process died holding it.
     */
    private function openRecord(UploadSession $session): ?PendingUpload
    {
        $record = $this->uploads->store->find($session->id);
        $now = $this->uploads->now();

        return $record !== null && $session->names($record) && !$this->claimHeld($record, $now) && $record->expiresAt > $now
            ? $record
            : null;
    }

    /** Whether a completion holds $record's claim: made within the lease (UploadStore::SESSION_CLAIM_LEASE). */
    private function claimHeld(PendingUpload $record, int $now): bool
    {
        return $record->claimedAt !== null && $record->claimedAt > $now - UploadStore::SESSION_CLAIM_LEASE;
    }

    /**
     * The upload a session completed to, still waiting for its action and
     * bound as the session was -- or the 404 of a session that is gone.
     */
    private function completed(UploadSession $session): PendingUpload
    {
        $kept = $this->uploads->store->find(self::resultId($this->uploads, $session->id));
        if ($kept === null || $kept->claimedAt !== null || $kept->type === ''
            || $kept->action !== $session->action || $kept->field !== $session->field
            || $kept->targetClass !== $session->targetClass || $kept->targetId !== $session->targetId
            || $kept->tenant !== $session->tenant || $kept->panel !== $session->panel
            || !hash_equals($kept->sessionHash, $session->sessionHash) || !hash_equals($kept->authHash, $session->authHash)) {
            // Claimed and not yet kept: another request is completing it,
            // and may yet fail for a reason that passes and give it back.
            $own = $this->uploads->store->find($session->id);
            if ($own !== null && $session->names($own) && $this->claimHeld($own, $this->uploads->now()) && $own->expiresAt > $this->uploads->now()) {
                throw self::completing();
            }

            throw self::gone();
        }

        return $kept;
    }

    /**
     * The id of the upload a session completes to: drawn from the session's
     * own by the application's key, so it is found from the session again,
     * and guessed by no one who does not hold one.
     */
    public static function resultId(Uploads $uploads, string $sessionId): string
    {
        return bin2hex(substr($uploads->ticketSigner->sign('upload-session-result:' . $sessionId), 0, 16));
    }

    /**
     * The parts the store holds, the record forgotten and the session gone
     * if the store has no such upload any more -- aborted by its lifecycle
     * rule, say.
     *
     * @return array<int, array{size: int, etag: string}>
     */
    private function heldParts(UploadSession $session, PendingUpload $record): array
    {
        $held = $this->multipart()->listParts($record->key, $session->uploadId);
        if ($held === null && $this->uploads->storage->size($record->key) === $session->size) {
            // Assembled by a completion whose keep failed for a reason that
            // passes: every part is in, and /complete keeps the object.
            $held = [];
            for ($number = 1; $number <= $session->partCount(); $number++) {
                $held[$number] = ['size' => $session->partSize($number), 'etag' => ''];
            }

            return $held;
        }
        if ($held === null) {
            $this->uploads->store->forget($record->id);

            throw self::gone();
        }

        return $held;
    }

    /**
     * Whether the store's upload has been completed into the object at the
     * session's key already, at the size declared: by a completion whose
     * keep failed for a reason that passes.
     */
    private function assembled(UploadSession $session, PendingUpload $record): bool
    {
        return $this->multipart()->listParts($record->key, $session->uploadId) === null
            && $this->uploads->storage->size($record->key) === $session->size;
    }

    private function heldOffset(UploadSession $session, PendingUpload $record): int
    {
        return self::contiguous($session, $this->heldParts($session, $record));
    }

    /**
     * How much of the file is in, from its start: every part from the first
     * held at exactly the size it was granted, up to the first that is not.
     *
     * @param array<int, array{size: int, etag: string}> $held
     */
    private static function contiguous(UploadSession $session, array $held): int
    {
        $offset = 0;
        for ($number = 1; $number <= $session->partCount(); $number++) {
            if (($held[$number]['size'] ?? -1) !== $session->partSize($number)) {
                break;
            }
            $offset += $session->partSize($number);
        }

        return $offset;
    }

    /**
     * The size of each part of a file sent to the store: 5 MiB -- the least
     * S3 takes for any part but the last -- or, for a file that would need
     * more than ten thousand of those, the whole MiBs that make ten thousand.
     */
    private static function partBytesFor(int $size): int
    {
        $mib = 1_048_576;

        return max(S3Storage::MIN_PART_BYTES, intdiv(intdiv($size + 9_999, 10_000) + $mib - 1, $mib) * $mib);
    }

    /**
     * @param resource $in
     */
    private static function copy(mixed $in, string $path, int $limit): void
    {
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException('No temporary file to gather an upload into.');
        }
        try {
            $copied = stream_copy_to_stream($in, $out, $limit + 1);
            if ($copied === false) {
                throw new StorageException('A partial upload could not be read.');
            }
            if ($copied > $limit) {
                throw UploadEndpoint::tooLarge();
            }
            if ($copied < $limit) {
                // Less than was found held -- a read cut short, a temporary
                // disk full: the server's fault, and the bytes kept for the
                // next try.
                throw new StorageException('A partial upload could not be copied whole.');
            }
        } finally {
            fclose($out);
        }
    }

    private function appendable(): AppendableStorage
    {
        $storage = $this->uploads->storage;

        return $storage instanceof AppendableStorage ? $storage : throw new HttpException(404, 'Files here are not sent through PHP in chunks.', 'not_found');
    }

    private function multipart(): MultipartStore
    {
        $storage = $this->uploads->storage;

        return $storage instanceof MultipartStore ? $storage : throw new HttpException(404, 'Files here are not sent to the store in parts.', 'not_found');
    }

    /** @return array{upload: string, name: string, size: int, type: string} */
    private static function described(PendingUpload $kept): array
    {
        return ['upload' => $kept->id, 'name' => $kept->name, 'size' => $kept->size, 'type' => $kept->type];
    }

    /** 409: the upload is at another offset than the one named, which the answer says. */
    private static function elsewhere(int $offset): HttpException
    {
        return new HttpException(409, "The upload holds {$offset} bytes.", 'upload_offset', [UploadEndpoint::OFFSET_HEADER => (string) $offset]);
    }

    /**
     * 425: another request has claimed the session and is keeping the file.
     * Asked again in a moment, it is the upload made, the 404 of a session
     * refused for good, or the session open again.
     */
    private static function completing(): HttpException
    {
        return new HttpException(425, 'The upload is being completed: ask again in a moment.', 'upload_completing', ['retry-after' => '2']);
    }

    private static function gone(): HttpException
    {
        return new HttpException(404, 'The upload session is over: completed, abandoned, or run out.', 'upload_session_gone');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private static function json(int $status, array $body, array $headers = []): Response
    {
        return Response::make($status, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [
            'content-type' => 'application/json',
            'cache-control' => 'private, no-store',
            'vary' => 'Cookie',
            ...$headers,
        ]);
    }
}
