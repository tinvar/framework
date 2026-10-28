<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Actions\TagInvalidation;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Export;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Auth\PanelContext;
use Tinvar\Data\Data;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Jobs\JobControl;
use Tinvar\Jobs\JobRefused;
use Tinvar\Jobs\WorkerStopping;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Query;
use Tinvar\Render\TenantClaim;
use Tinvar\Storage\StorageKey;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

/**
 * Starts and runs exports and imports: the arguments a start resolves, the
 * checks it and its job ask, the file an import is held to before it is
 * queued, and the work the job does.
 *
 * The job runs where JobRunner puts it -- the dispatching tenant, the user
 * on the auth epoch they started it on, the panel and the user's membership
 * of it -- and then the export's or import's own #[Authorize] is asked
 * again, with its models read again in that tenant: a start allowed an hour
 * ago is not a reason to write a file now.
 */
final readonly class TransferRunner
{
    /** Rows read from the database at a time: a page, never the table. */
    public const int PAGE = 500;

    /**
     * @param \Closure(class-string): object $resolver builds an export or import afresh for each run
     * @param (\Closure(\Throwable): void)|null $report where a failure the user is told of
     *        only in general goes, in full
     */
    public function __construct(
        private Transfers $transfers,
        private Orm $orm,
        private Gate $gate,
        private Validator $validator,
        private AuthContext $auth,
        private \Closure $resolver,
        private ?PanelContext $panels = null,
        private ?\Closure $report = null,
        // What the tags an import declares do once its rows are applied (ADR
        // 0075); none, and they do nothing.
        private ?TagInvalidation $invalidation = null,
    ) {}

    /**
     * A start's arguments: each model by its id, read in the tenant -- a
     * missing or foreign one is a 404 -- and each scalar coerced to its
     * type as an action's own parameters are; a 422 for one that is not.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function arguments(CompiledTransfer $transfer, array $input): array
    {
        $unknown = array_diff(array_keys($input), [...array_keys($transfer->models), ...array_keys($transfer->scalars)]);
        if ($unknown !== []) {
            throw new ValidationException(array_fill_keys(array_map(strval(...), $unknown), ['is not an argument of this ' . $transfer->kind . '.']));
        }

        $arguments = [];
        foreach ($transfer->models as $name => $class) {
            $id = $input[$name] ?? null;
            if ($id === null || $id === '') {
                if (!isset($transfer->optionalModels[$name])) {
                    throw new ValidationException([$name => ["{$name} is required."]]);
                }
                $arguments[$name] = null;
                continue;
            }
            if (!\is_string($id) && !\is_int($id)) {
                throw new ValidationException([$name => ["{$name} must be an identifier."]]);
            }
            $arguments[$name] = $this->orm->find($class, (string) $id) ?? throw NotFoundException::model($class, (string) $id);
        }

        foreach ($transfer->scalars as $name => $parameter) {
            if (!\array_key_exists($name, $input)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                if (!$parameter->allowsNull()) {
                    throw new ValidationException([$name => ["{$name} is required."]]);
                }
                $arguments[$name] = null;
                continue;
            }
            $arguments[$name] = $this->validator->coerceParameter($parameter, $transfer->class, $input[$name]);
        }

        return $arguments;
    }

    /**
     * Asks every check a start asks -- the class's and __invoke's, those on
     * a row's fields apart -- of the arguments.
     *
     * @param array<string, mixed> $arguments
     * @throws ForbiddenException
     */
    public function authorize(CompiledTransfer $transfer, array $arguments): void
    {
        $models = array_filter($arguments, static fn (mixed $value): bool => $value instanceof Model);
        $checks = $transfer->startChecks();
        $optional = array_keys($transfer->optionalModels);
        if (Checks::undecided($checks, $transfer->classLevelStartChecks(), $models, $optional)) {
            throw ForbiddenException::undecided($transfer->where());
        }
        foreach ($checks as $check) {
            if (Checks::applies($check, $models, $optional) && !$this->gate->allows($check, $models)) {
                throw ForbiddenException::check($check->ability, $transfer->where());
            }
        }
    }

    /**
     * Holds an import's file to what it declares before anything is stored
     * or queued: UTF-8 CSV text, a header naming its row's fields, and no
     * more rows than it takes.
     *
     * @param resource $stream
     * @return int how many rows, the header apart
     * @throws CsvRefused
     */
    public function scan(CompiledTransfer $transfer, mixed $stream): int
    {
        $rows = 0;
        $header = false;
        foreach (Csv::read($stream) as $cells) {
            if (!$header) {
                $this->columns($transfer, $cells);
                $header = true;
                continue;
            }
            if (++$rows > $transfer->maxRows) {
                throw new CsvRefused('import_too_many_rows', "The file has more than {$transfer->maxRows} rows.");
            }
        }
        if (!$header) {
            throw new CsvRefused('import_header', 'The file is empty: its first row names its columns.');
        }

        return $rows;
    }

    /**
     * The record a job runs for: in this tenant claim and panel, started by
     * this user, and not yet run -- a job run twice does its work once.
     */
    public function claim(string $id): ?TransferRecord
    {
        $user = $this->auth->id() ?? throw new JobRefused('user_missing', 'A transfer runs as the user who started it.');
        $record = $this->transfers->store->find($id, TenantClaim::current($this->orm), $this->panels?->currentOrNull() ?? '', $user);
        if ($record === null || !$this->transfers->store->claim($record->id)) {
            return null;
        }

        return $record->with(['status' => TransferRecord::RUNNING]);
    }

    /**
     * The record a paused export picks up again: in this tenant claim and
     * panel, started by this user, still running, and holding either the
     * file the checkpoint names or the one the checkpoint was paused from.
     * None for one finished or gone since; one that holds anything else is
     * failed, since what it wrote is not what this run would add to.
     *
     * A pause never touches the record (pause()): it stores its file and
     * goes back on its queue, and the record takes the file here, on the
     * run that picks it up. So a stop that cuts a run off between pausing
     * and being put back -- handed back with the checkpoint it ran from --
     * leaves the record and that checkpoint agreeing, and the run goes again
     * from there, never from a file the record no longer holds. Taking the
     * file deletes the one before it first, then names the new one: cut off
     * in between, the record still names the one before, as the checkpoint
     * does.
     *
     * @param array<array-key, mixed> $checkpoint
     */
    private function resume(string $id, array $checkpoint, JobControl $control): ?TransferRecord
    {
        $user = $this->auth->id() ?? throw new JobRefused('user_missing', 'A transfer runs as the user who started it.');
        $record = $this->transfers->store->find($id, TenantClaim::current($this->orm), $this->panels?->currentOrNull() ?? '', $user);
        $key = $checkpoint['key'] ?? null;
        $from = $checkpoint['from'] ?? null;
        $rows = $checkpoint['rows'] ?? null;
        // A first pause left its record `queued` (pause()), so a run cut off
        // before it was put back -- handed back with no checkpoint at all --
        // claims it and starts over. Its checkpoint's run claims it here.
        if ($record !== null && $record->status === TransferRecord::QUEUED && $from === '' && $record->key === '') {
            if (!$this->transfers->store->claim($record->id)) {
                return null;
            }
            $record = $record->with(['status' => TransferRecord::RUNNING]);
        }
        if ($record === null || $record->status !== TransferRecord::RUNNING) {
            return null;
        }
        $held = \is_string($key) && $key !== '' && hash_equals($record->key, $key);
        $before = !$held && \is_string($key) && $key !== '' && \is_string($from) && hash_equals($record->key, $from);
        if ((!$held && !$before) || !\is_string($key) || !\is_int($rows) || !$this->transfers->storage->exists($key)) {
            $this->fail($record, 'The export was stopped part of the way through, and what it had written could not be found. Start it again.', $control);

            return null;
        }
        if ($before) {
            if ($record->key !== '') {
                $this->transfers->storage->delete($record->key);
            }
            $record = $record->with(['key' => $key, 'rows' => $rows]);
            $this->transfers->store->update($record);
        }

        return $record;
    }

    /**
     * Writes an export's file, a page of rows at a time, to storage, and
     * the link to it to its user.
     *
     * Asked to stop part of the way through (JobControl::stopping()), it
     * pauses between pages: what it has written so far is stored, and the
     * job goes back on its queue with where to pick up -- the next page's
     * cursor, the rows so far and that file -- sealed in its payload. The
     * next run has the record take the file (resume()), reads it back and
     * goes on from the cursor, so each row is written once.
     *
     * @param array<string, mixed> $arguments as the job carried them, models read again
     */
    public function export(string $id, array $arguments, ?JobControl $control = null): void
    {
        $checkpoint = $control?->checkpoint();
        $record = $control === null || $checkpoint === null ? $this->claim($id) : $this->resume($id, $checkpoint, $control);
        if ($record === null) {
            return;
        }

        $transfer = $this->transfers->registry->export($record->name);
        if ($transfer === null) {
            $this->fail($record, 'This export no longer exists.', $control);

            return;
        }

        try {
            $this->authorize($transfer, $arguments);
        } catch (ForbiddenException) {
            $this->fail($record, 'You may no longer run this export.', $control);

            return;
        }

        $done = false;
        try {
            $this->exportFrom($record, $transfer, $arguments, $checkpoint, $control, $done);
        } catch (WorkerStopping $e) {
            // Stopped by the worker's stop, wherever it came -- mid-page, or
            // between storing a pause and being put back -- and handed back
            // to run again: not a failure. A first run's record goes back to
            // `queued` for the next run to claim; a resumed run's is left as
            // resume() made it, holding the file its checkpoint names.
            if ($checkpoint === null && !$done) {
                $this->transfers->store->update($record->with(['status' => TransferRecord::QUEUED]));
            }

            throw $e;
        }
    }

    /**
     * The export's work once its record is claimed or picked up: the rows,
     * then a pause or the finished file. $done turns true the moment the
     * record is: a stop after that leaves it done.
     *
     * @param array<string, mixed> $arguments
     * @param array<array-key, mixed>|null $checkpoint
     * @param-out bool $done
     */
    private function exportFrom(TransferRecord $record, CompiledTransfer $transfer, array $arguments, ?array $checkpoint, ?JobControl $control, bool &$done): void
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('No temporary stream to write an export to.');
        }

        try {
            $from = null;
            $rows = 0;
            if ($checkpoint !== null) {
                $restored = $this->restore($record, $checkpoint, $stream);
                if ($restored === null) {
                    $this->fail($record, 'The export was stopped part of the way through, and what it had written could not be read back. Start it again.', $control);

                    return;
                }
                [$from, $rows] = $restored;
            }

            try {
                [$rows, $pausedAt] = $this->orm->readOnly(fn (): array => $this->write($transfer, $record->format, $arguments, $stream, $from, $rows, $control));
            } catch (TooManyRows) {
                $this->fail($record, "It has more than {$transfer->maxRows} rows. Narrow it and start it again.", $control);

                return;
            } catch (WorkerStopping $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->fail($record, 'The file could not be written.', $control);

                throw $e;
            }

            if ($pausedAt !== null) {
                \assert($control !== null);
                $this->pause($record, $stream, $rows, $pausedAt, $control);

                return;
            }

            $size = ftell($stream);
            rewind($stream);
            $key = StorageKey::generate(Transfers::EXPORT_PREFIX, $record->format);
            $this->transfers->storage->put($key, $stream);
        } finally {
            fclose($stream);
        }

        $now = $this->transfers->now();
        $finished = $record->with([
            'status' => TransferRecord::DONE,
            'rows' => $rows,
            'key' => $key,
            'size' => $size === false ? 0 : $size,
            'finishedAt' => $now,
            'expiresAt' => $now + $this->transfers->keepFiles,
            'fileName' => \sprintf('%s-%s.%s', str_replace('.', '-', $record->name), gmdate('Ymd-His', $now), $record->format),
        ]);
        $this->transfers->store->update($finished);
        $done = true;

        // What earlier runs stored when they paused, now part of the whole:
        // the file the record held, and any a stop left behind.
        if ($record->key !== '') {
            $this->transfers->storage->delete($record->key);
        }
        $this->forgetPauses($record, $control);
        $this->notify($finished);
    }

    /**
     * Stores what an export has written so far, and pauses the job with
     * where to pick up: the cursor, the rows, the file, and the file the
     * record holds now, which the next run replaces with it (resume()).
     * The record is not given the file here. A first run's goes back to
     * `queued`: cut off before it is put back, the job runs again with no
     * checkpoint, and must find its record to claim.
     *
     * The file is named for this pause of this job (pauseKey()), the same
     * on every run that reaches it: a run cut off before it was put back
     * pauses there again and stores over it, leaving nothing behind.
     *
     * @param resource $stream
     */
    private function pause(TransferRecord $record, mixed $stream, int $rows, string $cursor, JobControl $control): void
    {
        rewind($stream);
        $key = self::pauseKey($record, $control, $control->releases() + 1);
        $this->transfers->storage->put($key, $stream);
        if ($control->checkpoint() === null) {
            $this->transfers->store->update($record->with(['status' => TransferRecord::QUEUED]));
        }

        $control->release(checkpoint: ['cursor' => $cursor, 'rows' => $rows, 'key' => $key, 'from' => $record->key]);
    }

    /**
     * The file the $n-th pause of this export's job stores: named from the
     * job's idempotency key, so the same on every run that reaches that
     * pause, and nothing anyone can work out from the record.
     */
    private static function pauseKey(TransferRecord $record, JobControl $control, int $n): string
    {
        $name = substr(hash('sha256', $control->idempotencyKey("export-pause\0{$record->id}\0{$n}")), 0, 32);

        return Transfers::EXPORT_PREFIX . '/' . $name . '.' . $record->format;
    }

    /** Deletes every file a pause of this job may have stored, up to the next one it could have reached. */
    private function forgetPauses(TransferRecord $record, ?JobControl $control): void
    {
        if ($control === null) {
            return;
        }
        for ($n = 1; $n <= $control->releases() + 1; $n++) {
            $this->transfers->storage->delete(self::pauseKey($record, $control, $n));
        }
    }

    /**
     * Reads back into $stream what a paused run stored, and where it
     * stopped: the cursor of the next page and the rows written. Null when
     * the checkpoint or the file is not what a pause leaves.
     *
     * @param array<array-key, mixed> $checkpoint
     * @param resource $stream
     * @return array{string, int}|null
     */
    private function restore(TransferRecord $record, array $checkpoint, mixed $stream): ?array
    {
        $cursor = $checkpoint['cursor'] ?? null;
        $rows = $checkpoint['rows'] ?? null;
        if (!\is_string($cursor) || $cursor === '' || !\is_int($rows) || $rows < 0) {
            return null;
        }

        $partial = $this->transfers->storage->read($record->key);
        if ($partial === null) {
            return null;
        }
        try {
            if (stream_copy_to_stream($partial, $stream) === false) {
                return null;
            }
        } finally {
            fclose($partial);
        }

        return [$cursor, $rows];
    }

    /**
     * Applies an import's rows, all of them or none: each validated through
     * its form request and its field checks, and applied in one transaction
     * that any refused row, or any row that throws, rolls back.
     *
     * @param array<string, mixed> $arguments as the job carried them, models read again
     */
    public function import(string $id, array $arguments): void
    {
        $record = $this->claim($id);
        if ($record === null) {
            return;
        }

        try {
            $this->applyImport($record, $arguments);
        } finally {
            // The file was the user's to give and is applied or refused now:
            // kept no longer than the job.
            if ($record->key !== '') {
                $this->transfers->storage->delete($record->key);
            }
        }
    }

    /** @param array<string, mixed> $arguments */
    private function applyImport(TransferRecord $record, array $arguments): void
    {
        $transfer = $this->transfers->registry->import($record->name);
        if ($transfer === null) {
            $this->fail($record, 'This import no longer exists.');

            return;
        }

        try {
            $this->authorize($transfer, $arguments);
        } catch (ForbiddenException) {
            $this->fail($record, 'You may no longer run this import.');

            return;
        }

        $stream = $record->key === '' ? null : $this->transfers->storage->read($record->key);
        if ($stream === null) {
            $this->fail($record, 'The file is gone.');

            return;
        }

        $failures = new RowFailures();
        try {
            $rows = $this->orm->transaction(function () use ($transfer, $arguments, $stream, $failures): int {
                // Noting every tenant a row ran as, as an action's body is.
                [$rows, $tenants] = $this->orm->tenantsEnteredBy(
                    fn (): int => $this->applyRows($transfer, $arguments, $stream, $failures),
                );
                if ($failures->count > 0) {
                    throw new RowsRefused();
                }

                // What it said it invalidates, as an action's result does:
                // inside the transaction -- the cached queries forgotten and
                // the once-shared holds dropped at once, a failure rolling
                // every row back -- and again, with the live clients told and
                // the static pages taken down, once it commits.
                $this->invalidation?->invalidateIn($transfer->revalidates, $tenants);

                return $rows;
            });
        } catch (RowsRefused) {
            $this->finish($record->with([
                'status' => TransferRecord::FAILED,
                'errorCount' => $failures->count,
                'errors' => $failures->kept,
                'failure' => $failures->count === 1 ? 'One row was refused, so nothing was changed.' : "{$failures->count} rows were refused, so nothing was changed.",
            ]));

            return;
        } catch (CsvRefused $e) {
            $this->fail($record, $e->getMessage() . ' Nothing was changed.');

            return;
        } catch (\Throwable $e) {
            $this->fail($record, 'A row could not be applied, so nothing was changed.');

            throw $e;
        } finally {
            fclose($stream);
        }

        $this->finish($record->with(['status' => TransferRecord::DONE, 'rows' => $rows]));
    }

    /**
     * Each row of the file: read, resolved, checked, validated and -- when
     * nothing before it failed -- applied.
     *
     * @param array<string, mixed> $arguments
     * @param resource $stream
     */
    private function applyRows(CompiledTransfer $transfer, array $arguments, mixed $stream, RowFailures $failures): int
    {
        $instance = $this->body($transfer);
        $rowClass = $transfer->row;
        \assert(is_subclass_of($rowClass, FormRequest::class));
        $startModels = array_filter($arguments, static fn (mixed $value): bool => $value instanceof Model);
        $rowChecks = $transfer->rowChecks();

        $columns = null;
        $rows = 0;
        foreach (Csv::read($stream) as $row => $cells) {
            if ($columns === null) {
                $columns = $this->columns($transfer, $cells);
                continue;
            }
            if (++$rows > $transfer->maxRows) {
                throw new CsvRefused('import_too_many_rows', "The file has more than {$transfer->maxRows} rows.");
            }
            if (\count($cells) !== \count($columns)) {
                $failures->add($row, '', 'has ' . \count($cells) . ' cells, and the header names ' . \count($columns) . '.');
                continue;
            }

            $input = [];
            foreach ($columns as $index => $column) {
                $input[$column] = Csv::value($cells[$index]);
            }

            // Each model field read in the tenant, as an action's are; one
            // not found here -- missing, or another tenant's -- refuses the
            // row, and says the same for both.
            $resolved = [];
            $found = true;
            foreach ($transfer->rowModels as $field => $class) {
                $id = $input[$field] ?? '';
                if ($id === '') {
                    continue;
                }
                $model = $this->orm->find($class, $id);
                if ($model === null) {
                    $failures->add($row, $field, "{$field} does not match an existing record.");
                    $found = false;
                    continue;
                }
                $resolved[$field] = $model;
            }
            if (!$found) {
                continue;
            }

            // Row checks are asked of the row's own models alone: a check
            // names exactly one input (TransferRegistry refuses a start
            // argument sharing a row field's name), and a row's value is
            // never put where a start argument's is read.
            $subjects = [...$startModels, ...$resolved];
            $allowed = true;
            foreach ($rowChecks as $check) {
                if ($check->argument !== null && isset($resolved[$check->argument]) && !$this->gate->allows($check, $resolved)) {
                    $failures->add($row, $check->argument, "{$check->argument} is not one you may use here.");
                    $allowed = false;
                }
            }
            if (!$allowed) {
                continue;
            }

            try {
                $request = $this->validator->validate($rowClass, $input, null, $resolved, $subjects);
            } catch (ValidationException $e) {
                foreach ($e->errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $failures->add($row, (string) $field, $message);
                    }
                }
                continue;
            }

            // Once a row has failed, the rest are only checked: the
            // transaction rolls back anyway, and applying more is work
            // undone.
            if ($failures->count === 0) {
                $instance(...[...$arguments, $transfer->rowParameter => $request]);
            }
        }

        if ($columns === null) {
            throw new CsvRefused('import_header', 'The file is empty: its first row names its columns.');
        }

        return $rows;
    }

    /**
     * The header, as the columns in the file's order: every one a field of
     * the row, each once, and every field the row requires among them.
     *
     * @param list<string> $cells
     * @return list<string>
     * @throws CsvRefused
     */
    private function columns(CompiledTransfer $transfer, array $cells): array
    {
        $columns = array_map(static fn (string $cell): string => trim($cell), $cells);
        $unknown = array_diff($columns, $transfer->columns);
        if ($unknown !== []) {
            throw new CsvRefused('import_header', 'The header names columns this import does not take; it takes ' . implode(', ', $transfer->columns) . '.');
        }
        if (\count(array_unique($columns)) !== \count($columns)) {
            throw new CsvRefused('import_header', 'The header names a column twice.');
        }

        $rowClass = $transfer->row;
        foreach ((new \ReflectionClass($rowClass))->getConstructor()?->getParameters() ?? [] as $field) {
            if (!\in_array($field->getName(), $columns, true) && !$field->isDefaultValueAvailable() && !$field->allowsNull()) {
                throw new CsvRefused('import_header', "The header has no {$field->getName()} column, which every row needs.");
            }
        }

        return $columns;
    }

    /**
     * Every row of the export, mapped to its row class, written to $stream
     * -- from the page $from opens, after the $rows an earlier run wrote,
     * when it picks up from a pause -- until the last page, or until the
     * worker is stopping between two pages.
     *
     * @param array<string, mixed> $arguments
     * @param resource $stream
     * @return array{int, string|null} the rows written in all, and the next page's cursor if it stopped before it
     * @throws TooManyRows
     */
    private function write(CompiledTransfer $transfer, string $format, array $arguments, mixed $stream, ?string $from = null, int $rows = 0, ?JobControl $control = null): array
    {
        $instance = $this->body($transfer);
        $query = $instance(...$arguments);
        if (!$query instanceof Query) {
            throw new \LogicException("{$transfer->where()} returned " . get_debug_type($query) . ', not a Query.');
        }

        if ($format === Export::CSV && $from === null) {
            Csv::write($stream, $transfer->columns);
        }

        // Keyset pages over the export's own order, by cursors no reader
        // ever holds: this run's, and the next run's through the job's
        // sealed checkpoint (ExportCursors).
        $cursors = new ExportCursors();
        $cursor = $from;
        do {
            $page = $query->cursorPaginate(self::PAGE, $cursor, $cursors);
            foreach ($page->items as $model) {
                if (++$rows > $transfer->maxRows) {
                    throw new TooManyRows();
                }
                $this->writeRow($transfer, $format, $this->row($transfer, $instance, $model), $stream);
            }
            $cursor = $page->next;
            // A page's models are not needed once written: the identity
            // map would otherwise hold every row of the table by the end.
            $this->orm->identityMap()->flush();
            if ($cursor !== null && $control !== null && $control->stopping()) {
                return [$rows, $cursor];
            }
        } while ($cursor !== null);

        return [$rows, null];
    }

    /** The export's or import's class, built afresh: its __invoke is the body. */
    private function body(CompiledTransfer $transfer): callable
    {
        $instance = ($this->resolver)($transfer->class);
        if (!\is_callable($instance)) {
            throw new \LogicException("{$transfer->where()} has no __invoke() to run.");
        }

        return $instance;
    }

    private function row(CompiledTransfer $transfer, callable $instance, mixed $model): Data
    {
        $class = $transfer->row;
        \assert(is_subclass_of($class, Data::class));
        if (!$model instanceof Model) {
            throw new \LogicException("{$transfer->where()} read " . get_debug_type($model) . ', not a model.');
        }
        $map = [$instance, 'row'];
        $row = $transfer->mapsRows && \is_callable($map) ? $map($model) : $class::from($model);
        if (!$row instanceof $class) {
            throw new \LogicException("{$transfer->where()} mapped a row to " . get_debug_type($row) . ", not {$class}.");
        }

        return $row;
    }

    /** @param resource $stream */
    private function writeRow(CompiledTransfer $transfer, string $format, Data $row, mixed $stream): void
    {
        $values = [];
        foreach ($transfer->columns as $column) {
            $value = $row->{$column};
            $values[$column] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        if ($format === Export::CSV) {
            Csv::write($stream, array_values($values));

            return;
        }

        $line = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        if (fwrite($stream, $line) !== \strlen($line)) {
            throw new \RuntimeException('A JSON line could not be written.');
        }
    }

    private function fail(TransferRecord $record, string $why, ?JobControl $control = null): void
    {
        // What a paused export had written: never a file anyone fetches.
        if ($record->kind === CompiledTransfer::EXPORT) {
            if ($record->key !== '') {
                $this->transfers->storage->delete($record->key);
                $record = $record->with(['key' => '']);
            }
            $this->forgetPauses($record, $control);
        }

        $this->finish($record->with(['status' => TransferRecord::FAILED, 'failure' => $why]));
    }

    private function finish(TransferRecord $record): void
    {
        $finished = $record->with(['finishedAt' => $this->transfers->now(), 'key' => $record->kind === CompiledTransfer::IMPORT ? '' : $record->key]);
        $this->transfers->store->update($finished);
        $this->notify($finished);
    }

    /** Tells the application, which a failure in does not undo. */
    private function notify(TransferRecord $record): void
    {
        $notifier = $this->transfers->notifier;
        $user = $this->auth->user();
        if ($notifier === null || $user === null) {
            return;
        }

        try {
            $link = $record->isDownloadable($this->transfers->now())
                ? $this->transfers->emailedLink($record, $user, $this->auth->epoch())
                : null;
            $notifier->finished($record, $user, $link);
        } catch (\Throwable $e) {
            if ($this->report === null) {
                throw $e;
            }
            ($this->report)($e);
        }
    }
}
