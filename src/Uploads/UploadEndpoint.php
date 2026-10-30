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
        return $request->path === self::PATH || $request->path === self::REFRESH_PATH;
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
        $user = (string) $this->auth->id();

        $file = $this->receivedFile($request);
        $size = filesize($file->path);
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
        $type = FileTypes::sniffFile($file->path);
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
        $kept = $file->path;
        if (FileTypes::isImage($type)) {
            $kept = $this->uploads->images->sanitize($file->path, $type);
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
                if ($kept !== $file->path) {
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
            if ($kept !== $file->path) {
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
            name: UploadName::clean($file->clientName),
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
