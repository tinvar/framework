<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Export;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Auth\PanelContext;
use Tinvar\Data\Data;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Jobs\JobRefused;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Query;
use Tinvar\Orm\SignedCursors;
use Tinvar\Render\TenantClaim;
use Tinvar\Storage\StorageKey;
use Tinvar\Support\Signer;
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
     * Writes an export's file, a page of rows at a time, to storage, and
     * the link to it to its user.
     *
     * @param array<string, mixed> $arguments as the job carried them, models read again
     */
    public function export(string $id, array $arguments): void
    {
        $record = $this->claim($id);
        if ($record === null) {
            return;
        }

        $transfer = $this->transfers->registry->export($record->name);
        if ($transfer === null) {
            $this->fail($record, 'This export no longer exists.');

            return;
        }

        try {
            $this->authorize($transfer, $arguments);
        } catch (ForbiddenException) {
            $this->fail($record, 'You may no longer run this export.');

            return;
        }

        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('No temporary stream to write an export to.');
        }

        try {
            try {
                $rows = $this->orm->readOnly(fn (): int => $this->write($transfer, $record->format, $arguments, $stream));
            } catch (TooManyRows) {
                $this->fail($record, "It has more than {$transfer->maxRows} rows. Narrow it and start it again.");

                return;
            } catch (\Throwable $e) {
                $this->fail($record, 'The file could not be written.');

                throw $e;
            }

            $size = ftell($stream);
            rewind($stream);
            $key = StorageKey::generate(Transfers::EXPORT_PREFIX, $record->format);
            $this->transfers->storage->put($key, $stream);
        } finally {
            fclose($stream);
        }

        $now = $this->transfers->now();
        $done = $record->with([
            'status' => TransferRecord::DONE,
            'rows' => $rows,
            'key' => $key,
            'size' => $size === false ? 0 : $size,
            'finishedAt' => $now,
            'expiresAt' => $now + $this->transfers->keepFiles,
            'fileName' => \sprintf('%s-%s.%s', str_replace('.', '-', $record->name), gmdate('Ymd-His', $now), $record->format),
        ]);
        $this->transfers->store->update($done);
        $this->notify($done);
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
                $rows = $this->applyRows($transfer, $arguments, $stream, $failures);
                if ($failures->count > 0) {
                    throw new RowsRefused();
                }

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
     * Every row of the export, mapped to its row class, written to $stream.
     *
     * @param array<string, mixed> $arguments
     * @param resource $stream
     * @throws TooManyRows
     */
    private function write(CompiledTransfer $transfer, string $format, array $arguments, mixed $stream): int
    {
        $instance = $this->body($transfer);
        $query = $instance(...$arguments);
        if (!$query instanceof Query) {
            throw new \LogicException("{$transfer->where()} returned " . get_debug_type($query) . ', not a Query.');
        }

        if ($format === Export::CSV) {
            Csv::write($stream, $transfer->columns);
        }

        // Keyset pages over the export's own order, by cursors no one but
        // this run ever holds: signed with a key drawn for it, and bound to
        // this transfer.
        $cursors = new SignedCursors(new Signer(bin2hex(random_bytes(32)), SignedCursors::PURPOSE), 'transfer');
        $rows = 0;
        $cursor = null;
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
        } while ($cursor !== null);

        return $rows;
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

    private function fail(TransferRecord $record, string $why): void
    {
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
