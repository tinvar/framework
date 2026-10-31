<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\EntryContext;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\UploadedFile;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;
use Tinvar\Storage\StorageKey;
use Tinvar\Support\Uuid;

/**
 * POST /_export/{name} and POST /_import/{name}: where a user starts one.
 *
 * In the order the security model sets out for an action, nothing stored
 * or queued until the end:
 *
 *   1. Behind the Kernel's stack for it: the session, CSRF, the `transfers`
 *      throttle and authentication -- no opt-out: a transfer runs as a user.
 *   2. The export or import the path names, by the registry and nothing
 *      else; then its own context, as calling it sets it: its tenant source,
 *      its panel's guard, membership check and stack, its own middleware.
 *   3. Its arguments, each model read in the tenant -- a missing or foreign
 *      id is a 404 -- and each scalar coerced; a 422 for one that is not.
 *   4. Its #[Authorize], every check the class and __invoke declare: a 403.
 *   5. An import's file: received whole, no larger than it declares (413),
 *      UTF-8 CSV text headed by its row's columns with no more rows than it
 *      takes (422, each with a code saying which).
 *   6. A record of it, bound to the tenant claim, the panel and the user;
 *      an import's file stored under a key the server makes; and the job
 *      queued, sealed with the same context. A 202 with the record's id.
 */
final readonly class TransferEndpoint
{
    public const string EXPORT_PREFIX = '/_export/';
    public const string IMPORT_PREFIX = '/_import/';

    /** The multipart field an import's file is sent in, and its arguments' prefix. */
    public const string FILE_FIELD = 'file';
    public const string ARGS_FIELD = 'args';

    /**
     * Transfers one user may have queued or running at once. Each is a job
     * and, for an import, a file on disk until it runs, so without a cap
     * one user could fill the queue and the disk at the throttle's pace.
     */
    public const int MAX_WAITING = 5;

    /** How long an import's file waits for its job: a day. */
    public const int KEEP_IMPORTS = 86_400;

    /** The multipart framing around a file, allowed on top of the largest one. */
    private const int ENVELOPE_BYTES = 65_536;

    /**
     * @param \Closure(class-string): Middleware $middlewareResolver builds a transfer's own middleware
     * @param list<class-string> $globalMiddleware already run around this endpoint
     */
    public function __construct(
        private Transfers $transfers,
        private TransferRunner $runner,
        private EntryContext $entry,
        private AuthContext $auth,
        private Orm $orm,
        private \Closure $middlewareResolver,
        private ?PanelContext $panels = null,
        private array $globalMiddleware = [],
        private int $maxBodyBytes = Limits::MAX_BODY_BYTES,
        private int $maxJsonDepth = Limits::MAX_JSON_DEPTH,
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::EXPORT_PREFIX) || str_starts_with($request->path, self::IMPORT_PREFIX);
    }

    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'Exports and imports are started with POST.', 'method_not_allowed', ['allow' => 'POST']);
        }
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        self::assertMethod($request);

        $export = str_starts_with($request->path, self::EXPORT_PREFIX);
        $name = substr($request->path, \strlen($export ? self::EXPORT_PREFIX : self::IMPORT_PREFIX));
        $transfer = $export ? $this->transfers->registry->export($name) : $this->transfers->registry->import($name);
        if ($transfer === null) {
            throw new NotFoundException('No such ' . ($export ? 'export.' : 'import.'));
        }

        $stack = [
            ...$this->entry->stackFor($transfer->tenantFrom, $this->entry->panelFor($transfer->panels, $transfer->class)),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_diff($transfer->middleware, $this->globalMiddleware)),
            ),
        ];

        return new Pipeline($stack)->run(
            $request,
            fn (Request $inner): Response => $export ? $this->startExport($inner, $transfer, $rawBody) : $this->startImport($inner, $transfer),
        );
    }

    private function startExport(Request $request, CompiledTransfer $transfer, string $rawBody): Response
    {
        $body = Limits::input($request, $rawBody, $this->maxBodyBytes, $this->maxJsonDepth);
        $format = $body['format'] ?? $transfer->formats[0];
        if (!\is_string($format) || !\in_array($format, $transfer->formats, true)) {
            throw new \Tinvar\Validation\ValidationException(['format' => ['format must be one of ' . implode(', ', $transfer->formats) . '.']]);
        }

        $arguments = $this->runner->arguments($transfer, self::argumentsOf($body));
        $this->runner->authorize($transfer, $arguments);

        $record = $this->record($transfer, $format);
        $this->transfers->store->add($record);
        $this->transfers->dispatch(new RunExport($record->id, $arguments));

        return self::accepted($record);
    }

    private function startImport(Request $request, CompiledTransfer $transfer): Response
    {
        $type = strtolower(trim(explode(';', $request->header('content-type') ?? '', 2)[0]));
        if ($type !== 'multipart/form-data') {
            throw new HttpException(415, 'Send an import as multipart/form-data.', 'unsupported_media_type');
        }

        // PHP has read the body by now; refusing an oversized one this early
        // still keeps it from being read, stored or queued.
        $length = $request->header('content-length');
        if ($length !== null && ctype_digit($length) && (int) $length > $transfer->maxBytes + self::ENVELOPE_BYTES) {
            throw self::tooLarge();
        }

        $body = Limits::form($request->body, $this->maxBodyBytes, $this->maxJsonDepth);
        $arguments = $this->runner->arguments($transfer, self::argumentsOf($body));
        $this->runner->authorize($transfer, $arguments);
        $record = $this->record($transfer, 'csv');

        $file = self::receivedFile($request);
        $size = filesize($file->path);
        if ($size === false) {
            throw new \RuntimeException('A received import cannot be measured.');
        }
        if ($size > $transfer->maxBytes) {
            throw self::tooLarge();
        }
        if ($size === 0) {
            throw new HttpException(422, 'The file is empty.', 'import_empty');
        }

        // The bytes, never the client's name or type for them: CSV text, as
        // the import reads it.
        $stream = fopen($file->path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('A received import cannot be read.');
        }
        try {
            try {
                $this->runner->scan($transfer, $stream);
            } catch (CsvRefused $e) {
                throw new HttpException(422, $e->getMessage(), $e->reason);
            }

            rewind($stream);
            $key = StorageKey::generate(Transfers::IMPORT_PREFIX, 'csv');
            $this->transfers->storage->put($key, $stream);
        } finally {
            fclose($stream);
        }

        // Kept until the job has read it, or a day: a job refused on the
        // worker never runs to remove it, and pruning does then.
        $record = $record->with(['key' => $key, 'size' => $size, 'expiresAt' => $record->createdAt + self::KEEP_IMPORTS]);
        try {
            $this->transfers->store->add($record);
            $this->transfers->dispatch(new RunImport($record->id, $arguments));
        } catch (\Throwable $e) {
            // A file with no job is one nothing would ever read, or remove.
            $this->transfers->storage->delete($key);

            throw $e;
        }

        return self::accepted($record);
    }

    /**
     * The record of a start: bound to where it was made -- the tenant claim
     * and panel in force, and the user -- which the job, the list a user is
     * shown and every download link are held to.
     */
    private function record(CompiledTransfer $transfer, string $format): TransferRecord
    {
        $user = $this->auth->id() ?? throw new HttpException(401, 'Sign in to start this.', 'unauthenticated');
        $tenant = TenantClaim::current($this->orm);
        $panel = $this->panels?->currentOrNull() ?? '';
        $now = $this->transfers->now();

        $waiting = array_filter(
            $this->transfers->store->recent($tenant, $panel, $user, self::MAX_WAITING * 4),
            static fn (TransferRecord $r): bool => ($r->status === TransferRecord::QUEUED || $r->status === TransferRecord::RUNNING)
                && !$r->isStale($now),
        );
        if (\count($waiting) >= self::MAX_WAITING) {
            throw new HttpException(429, 'Too many exports and imports are waiting to run.', 'transfers_waiting', [
                'retry-after' => '60',
            ]);
        }

        return new TransferRecord(
            id: Uuid::v7(),
            kind: $transfer->kind,
            name: $transfer->name,
            format: $format,
            tenant: $tenant,
            panel: $panel,
            user: $user,
            status: TransferRecord::QUEUED,
            createdAt: $now,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function argumentsOf(array $body): array
    {
        $args = $body[self::ARGS_FIELD] ?? [];
        if (!\is_array($args) || ($args !== [] && array_is_list($args))) {
            throw new HttpException(400, 'Arguments are an object, by name.', 'bad_request');
        }

        /** @var array<string, mixed> $args */
        return $args;
    }

    /** The one file the request carried, received whole, or the refusal that says why not. */
    private static function receivedFile(Request $request): UploadedFile
    {
        $file = $request->files[self::FILE_FIELD] ?? null;
        if ($file === null || $file->error === UPLOAD_ERR_NO_FILE) {
            throw new HttpException(422, 'Send the file in the `file` field.', 'import_missing');
        }

        return match ($file->error) {
            UPLOAD_ERR_OK => $file->isOk() ? $file : throw new HttpException(422, 'The file did not arrive.', 'import_missing'),
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw self::tooLarge(),
            UPLOAD_ERR_PARTIAL => throw new HttpException(400, 'The file arrived only in part.', 'import_partial'),
            default => throw new \RuntimeException("PHP could not receive an import (error {$file->error})."),
        };
    }

    private static function tooLarge(): HttpException
    {
        return new HttpException(413, 'The file is larger than this import takes.', 'import_too_large');
    }

    private static function accepted(TransferRecord $record): Response
    {
        return Response::make(202, json_encode(['transfer' => $record->id, 'status' => $record->status], JSON_THROW_ON_ERROR), [
            'content-type' => 'application/json',
            'cache-control' => 'private, no-store',
            'vary' => 'Cookie',
        ]);
    }
}
