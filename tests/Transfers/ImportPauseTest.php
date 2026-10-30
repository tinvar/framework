<?php

declare(strict_types=1);

/**
 * An import longer than a stop (ADR 0101): checked whole before a row is
 * written, written a chunk to a transaction, paused between two chunks
 * when its worker is stopping, and picked up on the next run where its
 * record says -- every row written once, wherever the stop lands.
 */

use Tinvar\Jobs\WorkerStopping;
use Tinvar\Queue\ReservedJob;
use Tinvar\Tests\Transfers\Fixtures\App;
use Tinvar\Tests\Transfers\Fixtures\RestockImport;
use Tinvar\Transfers\TransferRecord;
use Tinvar\Transfers\Transfers;
use Tinvar\Transfers\TransferRunner;

require_once __DIR__ . '/Fixtures/app.php';

/** Acme's $count restockable widgets, M-00000 up, each at stock 10. */
function restockable(int $count, bool $databaseStore = true): App
{
    $app = new App(databaseStore: $databaseStore);
    for ($i = 0; $i < $count; $i++) {
        $app->connection->execute(
            'INSERT INTO widgets (id, tenant_id, sku, name, stock, secret) VALUES (?, ?, ?, ?, ?, ?)',
            [widgetId($i), 'acme', sprintf('M-%05d', $i), "Many {$i}", 10, 'acme-secret'],
        );
    }

    return $app;
}

function widgetId(int $i): string
{
    return sprintf('0192f3a1-0000-7000-8000-%012d', 1000 + $i);
}

/** A file adding one to each of the first $count widgets. */
function restockFile(int $count): string
{
    $csv = "widget,add\n";
    for ($i = 0; $i < $count; $i++) {
        $csv .= widgetId($i) . ",1\n";
    }

    return $csv;
}

/** @return array<int, int> each widget's stock, by its number */
function stocks(App $app, int $count): array
{
    $rows = $app->connection->select("SELECT sku, stock FROM widgets WHERE sku LIKE 'M-%' ORDER BY sku");
    $out = [];
    foreach (array_slice($rows, 0, $count) as $row) {
        $out[(int) substr((string) $row['sku'], 2)] = (int) $row['stock'];
    }

    return $out;
}

/**
 * Takes the import's job and runs it once, as a worker would, with
 * $stopping as the worker's stop: put back paused when it pauses, handed
 * back as it was taken when the stop interrupts it.
 *
 * @param \Closure(): bool $stopping
 * @return 'done'|'paused'|'handed back'
 */
function runImportOnce(App $app, \Closure $stopping): string
{
    $job = $app->queue->reserve([Transfers::QUEUE], $app->now, 60);
    expect($job)->toBeInstanceOf(ReservedJob::class);
    \assert($job !== null);
    $envelope = $app->runner->open($job->payload);
    $control = $app->runner->control($envelope, $stopping);

    try {
        $app->runner->run($envelope, $control);
    } catch (WorkerStopping) {
        // The worker's boundary: a transaction the stop left open rolled
        // back, as the reset contract does, then the job handed back.
        $app->connection->rollBackOpenTransaction();
        $app->queue->requeue($job, $app->now);
        $app->orm->identityMap()->flush();

        return 'handed back';
    }
    $app->orm->identityMap()->flush();

    $release = $control->released();
    if ($release === null) {
        $app->queue->delete($job);

        return 'done';
    }
    $app->queue->requeue($job, $app->now + $release['delay'], $app->runner->pause($envelope, $release['checkpoint']));

    return 'paused';
}

/** A stop that comes after $calls answers of no. */
function stopAfter(int $calls): \Closure
{
    $asked = 0;

    return static function () use (&$asked, $calls): bool {
        return ++$asked > $calls;
    };
}

afterEach(function () {
    RestockImport::$stopAt = null;
});

it('pauses its check between chunks, writing nothing, and picks it up where it got to', function () {
    $count = TransferRunner::PAGE * 2 + 100;
    $app = restockable($count);
    expect($app->import(restockFile($count), name: 'restock')->status)->toBe(202);

    expect(runImportOnce($app, static fn (): bool => true))->toBe('paused');
    $record = $app->record();
    expect($record->status)->toBe(TransferRecord::QUEUED)
        ->and($record->checked)->toBe(TransferRunner::PAGE)
        ->and($record->rows)->toBe(0)
        ->and(array_unique(stocks($app, $count)))->toBe([10])
        // The file is kept for the run that picks it up.
        ->and($app->storage->keys())->toBe([$record->key]);

    expect(runImportOnce($app, static fn (): bool => false))->toBe('done');
    $record = $app->record();
    expect($record->status)->toBe(TransferRecord::DONE)
        ->and($record->rows)->toBe($count)
        ->and($record->checked)->toBe($count)
        ->and(array_unique(stocks($app, $count)))->toBe([11])
        ->and($app->storage->keys())->toBe([])
        ->and($app->queue->size(Transfers::QUEUE))->toBe(0);
});

it('pauses its write between chunks and writes every row once', function () {
    $count = TransferRunner::PAGE * 2 + 100;
    $app = restockable($count);
    $app->import(restockFile($count), name: 'restock');

    // Three chunks checked, then one written, then the stop.
    expect(runImportOnce($app, stopAfter(3)))->toBe('paused');
    $record = $app->record();
    $stocks = stocks($app, $count);
    expect($record->status)->toBe(TransferRecord::QUEUED)
        ->and($record->rows)->toBe(TransferRunner::PAGE)
        ->and(array_count_values($stocks))->toBe([11 => TransferRunner::PAGE, 10 => $count - TransferRunner::PAGE]);

    expect(runImportOnce($app, static fn (): bool => false))->toBe('done')
        ->and(array_unique(stocks($app, $count)))->toBe([11])
        ->and($app->record()->rows)->toBe($count);
});

it('writes each row once when the stop lands between a chunk\'s commit and its pause', function () {
    $count = TransferRunner::PAGE * 2 + 100;
    $app = restockable($count);
    $app->import(restockFile($count), name: 'restock');

    // The stop's interrupt, thrown where the run asks whether to pause:
    // the first chunk committed, and nothing has said so but the record.
    $asked = 0;
    $interrupt = static function () use (&$asked): bool {
        if (++$asked === 4) {
            throw WorkerStopping::in(RestockImport::class, 70);
        }

        return false;
    };
    expect(runImportOnce($app, $interrupt))->toBe('handed back')
        ->and($app->record()->status)->toBe(TransferRecord::QUEUED)
        ->and($app->record()->rows)->toBe(TransferRunner::PAGE);

    expect(runImportOnce($app, static fn (): bool => false))->toBe('done')
        ->and(array_unique(stocks($app, $count)))->toBe([11]);
});

it('writes each row once when the stop interrupts a chunk part of the way through', function () {
    $count = TransferRunner::PAGE * 2 + 100;
    $app = restockable($count);
    $app->import(restockFile($count), name: 'restock');

    // Inside the second chunk: its rows so far, and its count, roll back.
    RestockImport::$stopAt = sprintf('M-%05d', TransferRunner::PAGE + 200);
    expect(runImportOnce($app, static fn (): bool => false))->toBe('handed back');
    $stocks = stocks($app, $count);
    expect($app->record()->rows)->toBe(TransferRunner::PAGE)
        ->and(array_count_values($stocks))->toBe([11 => TransferRunner::PAGE, 10 => $count - TransferRunner::PAGE]);

    expect(runImportOnce($app, static fn (): bool => false))->toBe('done')
        ->and(array_unique(stocks($app, $count)))->toBe([11]);
});

it('writes in one transaction on a store that cannot commit with the rows, so a stop leaves none written', function () {
    $count = TransferRunner::PAGE * 2 + 100;
    $app = restockable($count, databaseStore: false);
    $app->import(restockFile($count), name: 'restock');

    RestockImport::$stopAt = sprintf('M-%05d', TransferRunner::PAGE * 2 + 50);
    expect(runImportOnce($app, static fn (): bool => false))->toBe('handed back')
        ->and($app->record()->rows)->toBe(0)
        ->and(array_unique(stocks($app, $count)))->toBe([10]);

    expect(runImportOnce($app, static fn (): bool => false))->toBe('done')
        ->and(array_unique(stocks($app, $count)))->toBe([11]);
});

it('stops at a row the write refuses, keeping the chunks before it and saying so', function () {
    $count = TransferRunner::PAGE * 2 + 100;
    $app = restockable($count);
    $app->import(restockFile($count), name: 'restock');
    expect(runImportOnce($app, stopAfter(3)))->toBe('paused');

    // Between the runs a widget in the third chunk is locked: the check
    // passed it, and the write is asked of it again.
    $locked = TransferRunner::PAGE * 2 + 10;
    $app->connection->execute("UPDATE widgets SET sku = 'LOCKED' WHERE id = ?", [widgetId($locked)]);

    expect(runImportOnce($app, static fn (): bool => false))->toBe('done');
    $record = $app->record();
    $stocks = $app->connection->select('SELECT stock FROM widgets WHERE id = ?', [widgetId(TransferRunner::PAGE * 2)]);
    expect($record->status)->toBe(TransferRecord::FAILED)
        ->and($record->rows)->toBe(TransferRunner::PAGE * 2)
        ->and($record->failure)->toContain('The first ' . (TransferRunner::PAGE * 2) . ' rows were imported; it and the rows after it were not')
        ->and($record->errors[0]['field'] ?? null)->toBe('widget')
        // The third chunk rolled back whole.
        ->and((int) $stocks[0]['stock'])->toBe(10)
        ->and($app->storage->keys())->toBe([]);
});

it('changes nothing when the check refuses a row, across a pause', function () {
    $count = TransferRunner::PAGE + 100;
    $app = restockable($count);
    $csv = restockFile($count) . 'not-a-widget,1' . "\n";
    $app->import($csv, name: 'restock');

    expect(runImportOnce($app, static fn (): bool => true))->toBe('paused');
    expect(runImportOnce($app, static fn (): bool => false))->toBe('done');

    $record = $app->record();
    expect($record->status)->toBe(TransferRecord::FAILED)
        ->and($record->failure)->toBe('One row was refused, so nothing was changed.')
        ->and($record->errorCount)->toBe(1)
        ->and($record->rows)->toBe(0)
        ->and(array_unique(stocks($app, $count)))->toBe([10]);
});

it('asks its #[Authorize] again on the run that picks it up', function () {
    $count = TransferRunner::PAGE + 100;
    $app = restockable($count);
    $app->import(restockFile($count), name: 'restock');
    expect(runImportOnce($app, stopAfter(2)))->toBe('paused');
    expect($app->record()->rows)->toBe(TransferRunner::PAGE);

    // Its user's epoch moves while it waits: the next run is refused
    // before it writes another row.
    $app->users->epochs['staff-1'] = 'e2';
    expect($app->work())->toBe(['epoch_changed'])
        ->and(array_count_values(stocks($app, $count)))->toBe([11 => TransferRunner::PAGE, 10 => 100]);

    // Shown failed at once (ADR 0107), with how many rows went in: started
    // again whole, those would be written twice. The rows stay, as ADR 0101
    // has them.
    $app->auth->login(new \Tinvar\Tests\Transfers\Fixtures\User('staff-1'), 'e2');
    $status = $app->orm->actAs('acme', fn (): array => $app->kernel->panels?->within('staff', fn (): array => $app->kernel->transferStatus?->recent() ?? []) ?? []);
    expect($status[0]->status)->toBe('failed')
        ->and($status[0]->rows)->toBe(TransferRunner::PAGE)
        ->and($status[0]->failure)->toBe('You may no longer run this import. The first ' . TransferRunner::PAGE . ' rows were imported before it stopped; the rest were not.')
        ->and($app->storage->keys())->toBe([]);
});
