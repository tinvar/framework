<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
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
use Tinvar\Validation\ValidationException;

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

    /**
     * How long past its URL's expiry a completed intent's key is kept in
     * mind, to be removed then. Completing an upload does not end the URL:
     * a PUT replayed after it puts the file there again, and a PUT a store
     * took before the URL ran out may still be arriving after.
     */
    public const int INCOMING_GRACE = 3600;

    /**
     * Where a resumable upload begins (ADR 0143): under its ticket, for the
     * size, name and type declared up front, answered with a session the
     * browser keeps -- and, for a file sent straight to an object store, a
     * multipart upload begun there.
     */
    public const string SESSION_PATH = '/_upload/session';

    /** Where a browser asks how much of a resumable upload is in: before it goes on, every time. */
    public const string SESSION_OFFSET_PATH = '/_upload/session/offset';

    /** Where a chunk is appended, at the offset it names, which must be the one held. */
    public const string SESSION_CHUNK_PATH = '/_upload/session/chunk';

    /** Where a browser sending parts to the store is told where to go on, and handed their URLs. */
    public const string SESSION_PARTS_PATH = '/_upload/session/parts';

    /** Where an upload sent in parts is completed, and kept -- or refused -- as /_upload would. */
    public const string SESSION_COMPLETE_PATH = '/_upload/session/complete';

    /** Where a resumable upload is given up: its bytes removed, its room given back. */
    public const string SESSION_ABORT_PATH = '/_upload/session/abort';

    /**
     * The prefix a resumable upload's bytes wait under until it is whole --
     * appended to through PHP, or a multipart upload in the store -- never
     * one an action declares, and never the direct uploads' prefix, which is
     * swept on a clock of minutes rather than a day.
     */
    public const string PARTIAL_PREFIX = 'tinvar-partial';

    /**
     * How long a resumable upload may take, from when it began: a day, the
     * longest any ticket lives. Long enough to go on the next morning after
     * a laptop was shut, or after a crash; short enough that the room it
     * holds against its owner's quota and the cap comes back the same day.
     */
    public const int SESSION_TTL = 86400;

    /** The chunk size a browser is asked to send through PHP: a lost one costs a second or two to send again. */
    public const int CHUNK_BYTES = 1_048_576;

    /** The largest chunk taken, well inside PHP's default post_max_size of 8 MB. */
    public const int MAX_CHUNK_BYTES = 4_194_304;

    /**
     * The smallest chunk taken but the last: a file of the largest size
     * any upload takes is a few dozen appends, never a million.
     */
    public const int MIN_CHUNK_BYTES = 262_144;

    /** Seconds a URL for one part is good for: minutes, enough to send it on a slow line. */
    public const int PART_TTL = 300;

    /** How many parts' URLs one question hands out: the next few, never the whole file's. */
    public const int PARTS_AHEAD = 4;

    /** The headers a chunk carries: its session, the offset it is for, and optionally its digest. */
    public const string SESSION_HEADER = 'upload-session';
    public const string OFFSET_HEADER = 'upload-offset';
    public const string CHECKSUM_HEADER = 'upload-checksum';

    /** Where a form posted without script carries each file's ticket: `_upload_tickets[<name>]`. */
    public const string INLINE_TICKETS = '_upload_tickets';

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
        return \in_array($request->path, [
            self::PATH, self::REFRESH_PATH, self::DIRECT_PATH, self::COMPLETE_PATH,
            self::SESSION_PATH, self::SESSION_OFFSET_PATH, self::SESSION_CHUNK_PATH,
            self::SESSION_PARTS_PATH, self::SESSION_COMPLETE_PATH, self::SESSION_ABORT_PATH,
        ], true);
    }

    /** Whether $request is a chunk of a resumable upload, which the Kernel throttles by a budget of its own. */
    public static function isChunk(Request $request): bool
    {
        return $request->path === self::SESSION_CHUNK_PATH;
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
        if (str_starts_with($request->path, self::SESSION_PATH)) {
            return new ResumableUploads($this, $this->uploads, $this->auth)->handle($request, $rawBody);
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

        return $this->within($action, $request, fn (Request $inner): Response => $this->store($inner, $ticket, $declared));
    }

    /**
     * Runs $then inside the action's own context: its tenant and panel
     * stack, then its own middleware, as ActionEndpoint puts them around a
     * call.
     *
     * @internal for ResumableUploads
     * @param \Closure(Request): Response $then
     */
    public function within(CompiledAction $action, Request $request, \Closure $then): Response
    {
        $panels = $this->entry->panelFor($action->panels, $action->class);
        $stack = [
            ...$this->entry->stackFor($action->tenantFrom, $panels),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_diff($action->middleware, $this->globalMiddleware)),
            ),
        ];

        return new Pipeline($stack)->run($request, $then);
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
                    // Removed by pruning, below, or the bucket's lifecycle rule.
                }
                // The URL is good until it runs out, and a PUT replayed
                // under it puts the file there again: the intent is kept,
                // consumed, and pruning removes its key once the URL is dead.
                $this->uploads->store->add($intent->consumed($now, self::INCOMING_GRACE));
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
     * @internal for ResumableUploads
     * @return array<mixed>
     */
    public static function jsonBody(Request $request, string $rawBody): array
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
     * @internal for ResumableUploads
     * @return array{CompiledAction, Uploaded}
     */
    public function declarationFor(UploadTicket $ticket): array
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
        return self::kept($this->keepFile($ticket, $declared, $path, $clientName));
    }

    /**
     * The answer for an upload kept: its reference, and the file as the
     * server saw it.
     *
     * @internal for ResumableUploads
     */
    public static function kept(PendingUpload $pending): Response
    {
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

    /**
     * keep(), answering with the upload recorded rather than a response --
     * under $id, given one: a resumable upload's, drawn from its session so
     * a completion asked about again finds it (ResumableUploads). $replaces
     * is the session's own record, still held while this runs: its room is
     * taken over, not counted beside this upload's.
     *
     * @internal for ResumableUploads
     */
    public function keepFile(UploadTicket $ticket, Uploaded $declared, string $path, string $clientName, ?string $id = null, ?PendingUpload $replaces = null): PendingUpload
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
        // A resumable session's record, claimed and kept until this has an
        // outcome, is room this upload takes over, not room beside it: it
        // goes once this is added, and is not counted against it.
        if ($quota !== null && $replaces !== null) {
            $quota += $replaces->size;
        }
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
            id: $id ?? bin2hex(random_bytes(16)),
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

        return $pending;
    }

    /**
     * The files a form posted without script carries straight to its
     * action, taken as /_upload would take each, and the action's input
     * with each file's reference in its place (ADR 0127).
     *
     * A browser with no script has nothing to send a file to /_upload with
     * first, so the form posts it -- multipart, under the parameter's own
     * name -- with the ticket the page rendered beside it, in
     * `_upload_tickets[<name>]`. Called by the action endpoint inside the
     * action's own context -- its tenant, its panel's stack, its middleware
     * -- which is the context /_upload runs, and each file is held to
     * exactly what /_upload holds one to: a ticket for this action and this
     * parameter, bound to this session, this user at their epoch, this
     * tenant and panel; the declared size and types; the cap and the quota;
     * drawn again and scanned; stored under a key of the server's. What is
     * refused is said on the field, as a form's errors are, for the page
     * the post is sent back to.
     *
     * Only an `Upload`: PHP gives a field of several files a nested shape
     * the request keeps none of (UploadedFile::fromGlobals()).
     *
     * A file refused leaves none of the post's kept: those taken before it
     * are discarded (discardTaken()) before the refusal is thrown.
     *
     * @param array<mixed> $input
     * @param list<string> $kept set to the ids of the uploads kept, for the caller to
     *        discard should the post fail its validation after all
     * @param-out list<string> $kept
     * @return array<mixed>
     * @throws ValidationException naming each field whose file was refused
     */
    public function takeInline(Request $request, string $name, array $input, ?array &$kept = null): array
    {
        $kept = [];
        $tickets = $input[self::INLINE_TICKETS] ?? [];
        unset($input[self::INLINE_TICKETS]);
        if ($request->files === [] || !$this->actions->has($name)) {
            return $input;
        }
        $action = $this->actions->get($name);

        $errors = [];
        foreach ($request->files as $field => $file) {
            $declared = $action->uploads[$field] ?? null;
            if ($declared === null || $declared->maxFiles !== 1 || $file->error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            try {
                $raw = \is_array($tickets) ? ($tickets[$field] ?? null) : null;
                if (!\is_string($raw) || $raw === '' || \strlen($raw) > self::MAX_TICKET_BYTES) {
                    throw self::refused('no ticket was posted beside the file');
                }
                try {
                    $ticket = UploadTicket::decode($raw, $this->uploads->ticketSigner, $this->uploads->now());
                } catch (TokenError $e) {
                    throw self::refused($e->getMessage());
                }
                if ($ticket->action !== $name || $ticket->field !== $field) {
                    throw self::refused('was issued for another action or parameter');
                }
                [, $declared] = $this->declarationFor($ticket);
                $this->assertBinding($request, $ticket);

                $received = $this->receivedFile($request, $field);
                $input[$field] = $kept[] = $this->keepFile($ticket, $declared, $received->path, $received->clientName)->id;
            } catch (HttpException $e) {
                $errors[$field] = [$e->status === 403 ? ActionRunner::UPLOAD_REFUSED : $e->getMessage()];
            }
        }

        if ($errors !== []) {
            // The post goes back with its errors, and its files must be
            // chosen again: none kept waiting against the quota and the cap.
            $this->discardTaken($kept);
            $kept = [];

            throw new ValidationException($errors);
        }

        return $input;
    }

    /**
     * Removes the uploads takeInline() kept for a post that then failed --
     * its validation, or another file refused -- each still waiting, so it
     * stops counting against its owner's quota and cap now rather than in
     * an hour. One an action has claimed is the action's, and left. A file
     * that cannot be removed is left, record and all, for pruning.
     *
     * @param list<string> $ids
     */
    public function discardTaken(array $ids): void
    {
        foreach ($ids as $id) {
            $upload = $this->uploads->store->find($id);
            if ($upload === null || $upload->claimedAt !== null) {
                continue;
            }
            try {
                $this->uploads->storage->delete($upload->key);
            } catch (StorageException) {
                continue;
            }
            $this->uploads->store->forget($id);
        }
    }

    /** The one file the request carried, received whole, or the refusal that says why not. */
    private function receivedFile(Request $request, string $field = self::FILE_FIELD): UploadedFile
    {
        $file = $request->files[$field] ?? null;
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

    /** @internal for ResumableUploads */
    public static function tooLarge(): HttpException
    {
        return new HttpException(413, 'The file is larger than this upload takes.', 'upload_too_large');
    }

    /**
     * Every reason a ticket is refused, alike to the client; the reason goes to the log.
     *
     * @internal for ResumableUploads
     */
    public static function refused(string $why): HttpException
    {
        return new HttpException(403, "The upload ticket is refused ({$why}).", 'upload_refused');
    }

    /**
     * Checked, never applied: the ticket agrees with where the request is,
     * and can put it nowhere.
     *
     * @internal for ResumableUploads
     */
    public function assertBinding(Request $request, UploadTicket $ticket): void
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
    /** @internal for ResumableUploads */
    public static function overQuota(): HttpException
    {
        return new HttpException(507, 'Your files take up all the space your account has.', 'upload_quota');
    }

    /** @internal for ResumableUploads */
    public static function tooManyPending(): HttpException
    {
        return new HttpException(429, 'Too many uploads are waiting to be saved.', 'upload_pending', [
            'retry-after' => (string) Uploads::PENDING_TTL,
        ]);
    }
}
