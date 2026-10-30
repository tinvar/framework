<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\CompiledAction;
use Tinvar\Attributes\Uploaded;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\UploadedFile;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenError;
use Tinvar\Storage\FileTypes;
use Tinvar\Storage\ObjectStore;
use Tinvar\Storage\StorageException;
use Tinvar\Storage\StorageKey;

/**
 * POST /_upload
 *
 * Takes one file under an upload ticket, stores it under a key the server
 * makes, and answers with a reference the form then sends to the action.
 *
 * tinvar-spec.md: "Uploaded files are referenced only by a server-issued
 * upload ticket bound to user, tenant, target model, key prefix and
 * expiry. Storage keys are server-generated; client filenames are metadata
 * only." In this order, nothing stored until the end:
 *
 *   1. Behind the Kernel's stack for it: the session, CSRF, the `uploads`
 *      throttle and authentication. A multipart body, carrying `ticket` and
 *      `file`.
 *   2. The ticket's signature and expiry; then the action and parameter it
 *      names, whose #[Uploaded] declaration -- never the ticket, never the
 *      client -- sets every limit below.
 *   3. The action's own context, as calling it would set it: its tenant
 *      source and its panel's guard, membership check and stack, then its
 *      own middleware. A session that could not call the action cannot
 *      upload to it.
 *   4. The ticket's binding against that context: the session, the user
 *      and auth epoch, the tenant and the panel. Steps 2 to 4 fail alike, a
 *      403, so a refusal says nothing about why.
 *   5. The file: received whole, within the declared size (413), sniffed
 *      as one of the declared types (415) -- the client's name and type for
 *      it play no part -- the user under the cap on uploads nobody has
 *      claimed yet (429) and under their quota (507), an image drawn again,
 *      and what would be kept scanned for malware where Uploads has a
 *      scanner (422 for a find, 503 for a scanner that could not answer).
 *   6. Stored under `<prefix>/<random>.<extension of the sniffed type>`, and
 *      recorded with the ticket's binding for the action to be held to.
 */
final class UploadEndpoint
{
    public const string PATH = '/_upload';

    /**
     * Where a page that has outlived its ticket asks for it again: the same
     * binding, holding for this request, issued for another hour -- but
     * never past a day from the ticket first issued (UploadTicket).
     */
    public const string REFRESH_PATH = '/_upload/refresh';

    /**
     * Where a browser asks to send a file straight to the object store:
     * under its ticket, for a size it names, answered with a URL presigned
     * for that size and a few minutes, and an intent to complete it by.
     */
    public const string DIRECT_PATH = '/_upload/direct';

    /**
     * Where it says the file is there: the file is fetched back from the
     * store, and kept exactly as one sent to /_upload is -- sniffed, drawn
     * again, scanned -- or refused.
     */
    public const string COMPLETE_PATH = '/_upload/complete';

    /** The prefix a file sent straight to the store waits under, never one an action declares. */
    public const string INCOMING_PREFIX = 'tinvar-incoming';

    /** Seconds a presigned upload, and the intent behind it, are good for. */
    public const int DIRECT_TTL = 600;

    /** The multipart fields: the ticket, and the file. */
    public const string TICKET_FIELD = 'ticket';
    public const string FILE_FIELD = 'file';

    /** A ticket is a path-sized string, not a payload. */
    public const int MAX_TICKET_BYTES = 4096;

    /**
     * Uploads one user may have stored and not yet claimed. Each is a file
     * on disk until it is claimed or pruned, so without a cap one ticket
     * would let a signed-in user fill the disk at the throttle's pace.
     * Counted by the user, not by their auth epoch: a sign-in moves that,
     * and a cap it started over would be no cap.
     */
    public const int MAX_PENDING = 20;

    /** The multipart framing around a file, allowed on top of the largest one. */
    private const int ENVELOPE_BYTES = 65_536;

    /**
     * @param \Closure(class-string): Middleware $middlewareResolver builds an action's own middleware
     * @param list<class-string> $globalMiddleware already run around this endpoint
     */
    public function __construct(
        private readonly Uploads $uploads,
        private readonly ActionRegistry $actions,
        private readonly EntryContext $entry,
        private readonly AuthContext $auth,
        private readonly Orm $orm,
        private readonly \Closure $middlewareResolver,
        private readonly ?PanelContext $panels = null,
        private readonly array $globalMiddleware = [],
    ) {}

    public function matches(Request $request): bool
    {
        return \in_array($request->path, [self::PATH, self::REFRESH_PATH, self::DIRECT_PATH, self::COMPLETE_PATH], true);
    }

    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'Uploads are POST only.', 'method_not_allowed', ['allow' => 'POST']);
        }
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        self::assertMethod($request);
        if ($request->path === self::REFRESH_PATH) {
            return $this->refresh($request, $rawBody);
        }
        if ($request->path === self::DIRECT_PATH || $request->path === self::COMPLETE_PATH) {
            return $this->direct($request, $rawBody);
        }

        $type = strtolower(trim(explode(';', $request->header('content-type') ?? '', 2)[0]));
        if ($type !== 'multipart/form-data') {
            throw new HttpException(415, 'Send an upload as multipart/form-data.', 'unsupported_media_type');
        }

        // PHP has read the body by now; refusing an oversized one this early
        // still keeps it from being sniffed, stored or recorded.
        $length = $request->header('content-length');
        if ($length !== null && ctype_digit($length) && (int) $length > $this->uploads->maxBytes + self::ENVELOPE_BYTES) {
            throw self::tooLarge();
        }

        $raw = $request->body[self::TICKET_FIELD] ?? null;
        if (!\is_string($raw) || $raw === '' || \strlen($raw) > self::MAX_TICKET_BYTES) {
            throw new HttpException(400, 'Send the upload ticket in the `ticket` field.', 'bad_request');
        }

        try {
            $ticket = UploadTicket::decode($raw, $this->uploads->ticketSigner, $this->uploads->now());
        } catch (TokenError $e) {
            throw self::refused($e->getMessage());
        }

        [$action, $declared] = $this->declarationFor($ticket);

        // The action's tenant and panel stack, then its own middleware, as
        // ActionEndpoint puts them around a call.
        $panels = $this->entry->panelFor($action->panels, $action->class);
        $stack = [
            ...$this->entry->stackFor($action->tenantFrom, $panels),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_diff($action->middleware, $this->globalMiddleware)),
            ),
        ];

        return new Pipeline($stack)->run($request, fn (Request $inner): Response => $this->store($inner, $ticket, $declared));
    }

    /**
     * The ticket again, for another hour. Only for the binding it was
     * issued under, holding for this request as an upload would have to:
     * this session, this user at this auth epoch, the tenant and panel the
     * action's own stack resolves -- so a sign-out, a sign-in elsewhere
     * that moved the epoch, or a moved tenant refuses it -- and a record
     * still there to attach files to, found as the tenant sees it. A day
     * from the first issue at most: past it, the page is loaded again.
     */
    private function refresh(Request $request, string $rawBody): Response
    {
        $raw = $request->body[self::TICKET_FIELD] ?? null;
        if (!\is_string($raw) && $rawBody !== '' && \strlen($rawBody) <= self::MAX_TICKET_BYTES + 64) {
            try {
                $json = json_decode($rawBody, true, 4, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $json = null;
            }
            $raw = \is_array($json) ? ($json[self::TICKET_FIELD] ?? null) : null;
        }
        if (!\is_string($raw) || $raw === '' || \strlen($raw) > self::MAX_TICKET_BYTES) {
            throw new HttpException(400, 'Send the upload ticket in the `ticket` field.', 'bad_request');
        }

        $now = $this->uploads->now();
        try {
            $ticket = UploadTicket::decodeForRefresh($raw, $this->uploads->ticketSigner, $now);
        } catch (TokenError $e) {
            throw self::refused($e->getMessage());
        }

        [$action, $declared] = $this->declarationFor($ticket);
        // The model the declaration names, which declarationFor() held the
        // ticket's class to; none for a parameter attached to no record.
        $model = $declared->to === null ? null : $action->models[$declared->to];
        $panels = $this->entry->panelFor($action->panels, $action->class);
        $stack = [
            ...$this->entry->stackFor($action->tenantFrom, $panels),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_diff($action->middleware, $this->globalMiddleware)),
            ),
        ];

        return new Pipeline($stack)->run($request, function (Request $inner) use ($ticket, $now, $model): Response {
            $this->assertBinding($inner, $ticket);
            if ($model !== null && $this->orm->find($model, $ticket->targetId) === null) {
                throw self::refused('names a record that is not there');
            }

            $renewed = $ticket->renewedAt($now);

            return Response::make(200, json_encode([
                'ticket' => $renewed->encode($this->uploads->ticketSigner),
                'expiresAt' => $renewed->expiresAt,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), [
                'content-type' => 'application/json',
                'cache-control' => 'private, no-store',
                'vary' => 'Cookie',
            ]);
        });
    }

    /**
     * A file sent straight to the object store, in two steps, each behind
     * the stack /_upload is, inside the action's own context, held to the
     * ticket's binding:
     *
     *   - /_upload/direct: the size the file is, within the declared limit,
     *     the cap and the quota, which an intent is recorded for -- counted
     *     against both as an upload waiting is, and pruned with them -- and a
     *     URL presigned to PUT exactly that many bytes at a key under
     *     INCOMING_PREFIX, as application/octet-stream, for DIRECT_TTL.
     *   - /_upload/complete: the intent, this ticket's, this session's and
     *     this user's, claimed once; the file fetched back, no larger than
     *     named, and removed from the store with the intent; then kept as a
     *     file sent to /_upload is, or refused as one would be.
     *
     * So nothing a browser sent to the store is ever served, attached or
     * kept as it was sent: what is kept is what PHP sniffed, drew again and
     * scanned. What goes past PHP is the slow part -- the client's upload --
     * and not the check.
     */
    private function direct(Request $request, string $rawBody): Response
    {
        $storage = $this->uploads->storage;
        if (!$storage instanceof ObjectStore) {
            throw new HttpException(404, 'Files here are sent to /_upload.', 'not_found');
        }

        $body = self::jsonBody($request, $rawBody);
        $raw = $body[self::TICKET_FIELD] ?? null;
        if (!\is_string($raw) || $raw === '' || \strlen($raw) > self::MAX_TICKET_BYTES) {
            throw new HttpException(400, 'Send the upload ticket in the `ticket` field.', 'bad_request');
        }
        try {
            $ticket = UploadTicket::decode($raw, $this->uploads->ticketSigner, $this->uploads->now());
        } catch (TokenError $e) {
            throw self::refused($e->getMessage());
        }
        [$action, $declared] = $this->declarationFor($ticket);

        $panels = $this->entry->panelFor($action->panels, $action->class);
        $stack = [
            ...$this->entry->stackFor($action->tenantFrom, $panels),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_diff($action->middleware, $this->globalMiddleware)),
            ),
        ];

        return new Pipeline($stack)->run($request, function (Request $inner) use ($storage, $body, $ticket, $declared): Response {
            $this->assertBinding($inner, $ticket);

            return $inner->path === self::DIRECT_PATH
                ? $this->begin($storage, $body, $ticket)
                : $this->complete($storage, $body, $ticket, $declared);
        });
    }

    /** @param array<mixed> $body */
    private function begin(ObjectStore $storage, array $body, UploadTicket $ticket): Response
    {
        [, $declared] = $this->declarationFor($ticket);
        $size = $body['size'] ?? null;
        if (!\is_int($size) || $size < 0) {
            throw new HttpException(400, 'Send the size of the file in bytes, as `size`.', 'bad_request');
        }
        if ($size === 0) {
            throw new HttpException(422, 'The file is empty.', 'upload_empty');
        }
        if ($size > min($declared->maxBytes, $this->uploads->maxBytes)) {
            throw self::tooLarge();
        }
        $name = \is_string($body['name'] ?? null) ? $body['name'] : '';

        $user = (string) $this->auth->id();
        $now = $this->uploads->now();
        $owner = $this->uploads->ownerHash($user);
        $quota = $this->uploads->quota?->bytesFor($user);

        $intent = new PendingUpload(
            id: bin2hex(random_bytes(16)),
            key: StorageKey::generate(self::INCOMING_PREFIX, ''),
            action: $ticket->action,
            field: $ticket->field,
            targetClass: $ticket->targetClass,
            targetId: $ticket->targetId,
            prefix: $ticket->prefix,
            tenant: $ticket->tenant,
            panel: $ticket->panel,
            sessionHash: $ticket->sessionHash,
            authHash: $ticket->authHash,
            name: UploadName::clean($name),
            // No type until PHP has sniffed it: an intent is never one an
            // action could be handed (UploadBinder).
            type: '',
            size: $size,
            createdAt: $now,
            expiresAt: $now + self::DIRECT_TTL,
            ownerHash: $owner,
        );
        if (!$this->uploads->store->addWithin($intent, self::MAX_PENDING, $now, $quota)) {
            throw $quota !== null && $this->uploads->store->bytesHeld($owner, $now) + $size > $quota
                ? self::overQuota()
                : self::tooManyPending();
        }

        $put = $storage->presignedPut($intent->key, $size, self::DIRECT_TTL);

        return Response::make(201, json_encode([
            'intent' => $intent->id,
            ...$put->jsonSerialize(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), [
            'content-type' => 'application/json',
            'cache-control' => 'private, no-store',
            'vary' => 'Cookie',
        ]);
    }

    /** @param array<mixed> $body */
    private function complete(ObjectStore $storage, array $body, UploadTicket $ticket, Uploaded $declared): Response
    {
        $id = $body['intent'] ?? null;
        if (!\is_string($id) || preg_match(UploadBinder::REFERENCE, $id) !== 1) {
            throw new HttpException(400, 'Send the intent /_upload/direct answered with, as `intent`.', 'bad_request');
        }

        $now = $this->uploads->now();
        $intent = $this->uploads->store->find($id);
        if ($intent === null || $intent->type !== '' || !str_starts_with($intent->key, self::INCOMING_PREFIX . '/')
            || $intent->claimedAt !== null || $intent->expiresAt <= $now
            || $intent->action !== $ticket->action || $intent->field !== $ticket->field
            || $intent->targetClass !== $ticket->targetClass || $intent->targetId !== $ticket->targetId
            || $intent->prefix !== $ticket->prefix || $intent->tenant !== $ticket->tenant || $intent->panel !== $ticket->panel
            || !hash_equals($intent->sessionHash, $ticket->sessionHash) || !hash_equals($intent->authHash, $ticket->authHash)
            // Once: of two completions, one goes on.
            || !$this->uploads->store->claim($intent->id, $now)) {
            throw self::refused('names no upload this ticket began');
        }

        $path = tempnam(sys_get_temp_dir(), 'tinvar-direct-');
        if ($path === false) {
            throw new \RuntimeException('No temporary file to fetch an upload into.');
        }

        try {
            try {
                $out = fopen($path, 'wb');
                if ($out === false) {
                    throw new \RuntimeException('No temporary file to fetch an upload into.');
                }
                $bytes = 0;
                try {
                    // No more than was named: the store held the PUT to it,
                    // and this holds the read to it too.
                    $found = $storage->readTo($intent->key, static function (string $chunk) use ($out, $intent, &$bytes): void {
                        $bytes += \strlen($chunk);
                        if ($bytes > $intent->size) {
                            throw self::tooLarge();
                        }
                        fwrite($out, $chunk);
                    });
                } finally {
                    fclose($out);
                }
            } finally {
                // Gone from the store whatever comes of it: a file sent there
                // is fetched once, and never kept as it was sent.
                try {
                    $storage->delete($intent->key);
                } catch (StorageException) {
                    // Left for the bucket's lifecycle rule on the prefix.
                }
                $this->uploads->store->forget($intent->id);
            }
            if (!$found || $bytes !== $intent->size) {
                throw new HttpException(422, 'The file did not arrive.', 'upload_missing');
            }

            return $this->keep($ticket, $declared, $path, $intent->name);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A small JSON body's fields -- or a form's, as PHP parsed it.
     *
     * @return array<mixed>
     */
    private static function jsonBody(Request $request, string $rawBody): array
    {
        if ($rawBody === '') {
            return $request->body;
        }
        if (\strlen($rawBody) > self::MAX_TICKET_BYTES + 1024) {
            throw new HttpException(413, 'The body is larger than this takes.', 'body_too_large');
        }
        try {
            $json = json_decode($rawBody, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Send the body as a JSON object.', 'bad_json');
        }

        return \is_array($json) ? $json : [];
    }

    /**
     * The action and the declaration a ticket names, as they stand now: a
     * ticket minted before a deploy that renamed the action, retargeted the
     * parameter or moved its prefix names something this server no longer
     * declares, and is refused.
     *
     * @return array{CompiledAction, Uploaded}
     */
    private function declarationFor(UploadTicket $ticket): array
    {
        if (!$this->actions->has($ticket->action)) {
            throw self::refused('names no registered action');
        }
        $action = $this->actions->get($ticket->action);
        $declared = $action->uploads[$ticket->field] ?? throw self::refused('names no upload parameter of the action');

        // A record of the declared model, by an id -- or, attached to no
        // record (`to: null`), neither a model nor an id.
        $holds = $declared->to === null
            ? $ticket->targetClass === '' && $ticket->targetId === ''
            : $ticket->targetClass === $action->models[$declared->to] && $ticket->targetId !== '';
        if (!$holds || $ticket->prefix !== $declared->prefix) {
            throw self::refused('names another target or prefix than the parameter declares');
        }

        return [$action, $declared];
    }

    private function store(Request $request, UploadTicket $ticket, Uploaded $declared): Response
    {
        $this->assertBinding($request, $ticket);
        $file = $this->receivedFile($request);

        return $this->keep($ticket, $declared, $file->path, $file->clientName);
    }

    /**
     * Steps 5 and 6 for a file on this machine at $path, received under
     * $ticket -- sent to /_upload, or fetched back from the store it was
     * sent to directly: measured, sniffed, drawn again, scanned, counted
     * against the cap and the quota, stored under a key of the server's
     * making and recorded. $path is the caller's to remove.
     */
    private function keep(UploadTicket $ticket, Uploaded $declared, string $path, string $clientName): Response
    {
        $user = (string) $this->auth->id();
        $size = filesize($path);
        if ($size === false) {
            throw new \RuntimeException('A received upload cannot be measured.');
        }
        if ($size > min($declared->maxBytes, $this->uploads->maxBytes)) {
            throw self::tooLarge();
        }
        if ($size === 0) {
            throw new HttpException(422, 'The file is empty.', 'upload_empty');
        }

        // The bytes say what it is; the client's name and type are not asked.
        $type = FileTypes::sniffFile($path);
        if ($type === null || !\in_array($type, $declared->types, true)) {
            throw new HttpException(415, 'The file is not of a type this upload takes.', 'upload_type');
        }

        $now = $this->uploads->now();
        $owner = $this->uploads->ownerHash($user);
        $quota = $this->uploads->quota?->bytesFor($user);
        // Asked here to spare storing a file that would be refused; the
        // count that holds is addWithin()'s, below, made with the add.
        if ($this->uploads->store->pendingCount($owner, $now) >= self::MAX_PENDING) {
            throw self::tooManyPending();
        }
        if ($quota !== null && $this->uploads->store->bytesHeld($owner, $now) + $size > $quota) {
            throw self::overQuota();
        }

        // An image is kept as drawn again from its pixels, never as sent:
        // its metadata -- a photo's GPS position among it -- and anything
        // after its pixels go (ImageSanitizer).
        $kept = $path;
        if (FileTypes::isImage($type)) {
            $kept = $this->uploads->images->sanitize($path, $type);
            $size = filesize($kept);
            if ($size === false || $size > min($declared->maxBytes, $this->uploads->maxBytes)) {
                @unlink($kept);
                throw self::tooLarge();
            }
        }

        // What would be kept, scanned before it is: refused if anything is
        // found, and refused if it cannot be scanned -- never kept unscanned.
        if ($this->uploads->scanner !== null) {
            try {
                $found = $this->uploads->scanner->scan($kept);
            } catch (ScanFailed $e) {
                $found = $e;
            }
            if ($found !== null) {
                if ($kept !== $path) {
                    @unlink($kept);
                }

                throw $found instanceof ScanFailed
                    ? new HttpException(503, 'The file could not be checked for malware, so it was not kept.', 'upload_scan_failed', ['retry-after' => '60'])
                    : new HttpException(422, 'The file carries malware, so it was not kept.', 'upload_infected');
            }
        }

        $key = StorageKey::generate($declared->prefix, FileTypes::extension($type));
        try {
            $stream = fopen($kept, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('A received upload cannot be read.');
            }
            try {
                $this->uploads->storage->put($key, $stream);
            } finally {
                fclose($stream);
            }
        } finally {
            if ($kept !== $path) {
                @unlink($kept);
            }
        }

        $pending = new PendingUpload(
            id: bin2hex(random_bytes(16)),
            key: $key,
            action: $ticket->action,
            field: $ticket->field,
            targetClass: $ticket->targetClass,
            targetId: $ticket->targetId,
            prefix: $ticket->prefix,
            tenant: $ticket->tenant,
            panel: $ticket->panel,
            sessionHash: $ticket->sessionHash,
            authHash: $ticket->authHash,
            name: UploadName::clean($clientName),
            type: $type,
            size: $size,
            createdAt: $now,
            expiresAt: $now + Uploads::PENDING_TTL,
            ownerHash: $owner,
        );

        try {
            $added = $this->uploads->store->addWithin($pending, self::MAX_PENDING, $now, $quota);
        } catch (\Throwable $e) {
            // A file with no record is one nothing would ever prune.
            $this->uploads->storage->delete($key);

            throw $e;
        }
        if (!$added) {
            // Another upload of the user's took the last place, or the last
            // of their quota, while this one was being stored.
            $this->uploads->storage->delete($key);

            throw $quota !== null && $this->uploads->store->bytesHeld($owner, $now) + $size > $quota
                ? self::overQuota()
                : self::tooManyPending();
        }

        return Response::make(201, json_encode([
            'upload' => $pending->id,
            'name' => $pending->name,
            'size' => $pending->size,
            'type' => $pending->type,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [
            'content-type' => 'application/json',
            'cache-control' => 'private, no-store',
            'vary' => 'Cookie',
        ]);
    }

    /** The one file the request carried, received whole, or the refusal that says why not. */
    private function receivedFile(Request $request): UploadedFile
    {
        $file = $request->files[self::FILE_FIELD] ?? null;
        if ($file === null || $file->error === UPLOAD_ERR_NO_FILE) {
            throw new HttpException(422, 'Send the file in the `file` field.', 'upload_missing');
        }

        return match ($file->error) {
            UPLOAD_ERR_OK => $file->isOk() ? $file : throw new HttpException(422, 'The file did not arrive.', 'upload_missing'),
            // PHP's own ceilings, met before any of this ran.
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw self::tooLarge(),
            UPLOAD_ERR_PARTIAL => throw new HttpException(400, 'The file arrived only in part.', 'upload_partial'),
            default => throw new \RuntimeException("PHP could not receive an upload (error {$file->error})."),
        };
    }

    private static function tooLarge(): HttpException
    {
        return new HttpException(413, 'The file is larger than this upload takes.', 'upload_too_large');
    }

    /** Every reason a ticket is refused, alike to the client; the reason goes to the log. */
    private static function refused(string $why): HttpException
    {
        return new HttpException(403, "The upload ticket is refused ({$why}).", 'upload_refused');
    }

    /**
     * Checked, never applied: the ticket agrees with where the request is,
     * and can put it nowhere.
     */
    private function assertBinding(Request $request, UploadTicket $ticket): void
    {
        $session = $this->uploads->sessionOf($request);
        if (TenantClaim::current($this->orm) !== $ticket->tenant
            || ($this->panels?->currentOrNull() ?? '') !== $ticket->panel
            || $session === ''
            || $this->auth->id() === null
            || !hash_equals($ticket->sessionHash, $this->uploads->sessionHash($session))
            || !hash_equals($ticket->authHash, $this->uploads->authHash($this->auth->id(), $this->auth->epoch()))) {
            throw self::refused('was issued to another session, user, tenant or panel');
        }
    }

    /**
     * 507, Insufficient Storage: the file is fine, and the user's quota has
     * no room for it -- not a 413, which says the file is too large for the
     * field, and would send them looking for a smaller one.
     */
    private static function overQuota(): HttpException
    {
        return new HttpException(507, 'Your files take up all the space your account has.', 'upload_quota');
    }

    private static function tooManyPending(): HttpException
    {
        return new HttpException(429, 'Too many uploads are waiting to be saved.', 'upload_pending', [
            'retry-after' => (string) Uploads::PENDING_TTL,
        ]);
    }
}
