<?php

declare(strict_types=1);

/**
 * An export longer than a stop: paused between two pages when its worker is
 * stopping, its file so far stored and held by its record, and picked up on
 * the next run from the next page -- every row once, the header once.
 */

use Tinvar\Jobs\Envelope;
use Tinvar\Jobs\WorkerStopping;
use Tinvar\Queue\ReservedJob;
use Tinvar\Tests\Transfers\Fixtures\App;
use Tinvar\Transfers\TransferRecord;
use Tinvar\Transfers\Transfers;
use Tinvar\Transfers\TransferRunner;

require_once __DIR__ . '/Fixtures/app.php';

/** An app whose acme has more widgets than three pages hold. */
function manyWidgets(int $extra): App
{
    $app = new App();
    for ($i = 0; $i < $extra; $i++) {
        $app->connection->execute(
            'INSERT INTO widgets (id, tenant_id, sku, name, stock, secret) VALUES (?, ?, ?, ?, ?, ?)',
            [sprintf('0192f3a1-0000-7000-8000-%012d', 1000 + $i), 'acme', sprintf('M-%05d', $i), "Many {$i}", $i, 'acme-secret'],
        );
    }

    return $app;
}

/**
 * Takes the export's job and runs it once, as a worker would, with
 * $stopping as the worker's stop; puts it back paused, as the worker does,
 * when it pauses.
 *
 * @param \Closure(): bool $stopping
 * @return array<array-key, mixed>|null the checkpoint it paused with
 */
function runOnce(App $app, \Closure $stopping): ?array
{
    $job = $app->queue->reserve([Transfers::QUEUE], $app->now, 60);
    expect($job)->toBeInstanceOf(ReservedJob::class);
    \assert($job !== null);
    $envelope = $app->runner->open($job->payload);
    $control = $app->runner->control($envelope, $stopping);

    try {
        $app->runner->run($envelope, $control);
    } catch (WorkerStopping) {
        $app->queue->requeue($job, $app->now);
        $app->orm->identityMap()->flush();

        return null;
    }
    $app->orm->identityMap()->flush();

    $release = $control->released();
    if ($release === null) {
        $app->queue->delete($job);

        return null;
    }
    $app->queue->requeue($job, $app->now + $release['delay'], $app->runner->pause($envelope, $release['checkpoint']));

    return $release['checkpoint'];
}

it('pauses between pages and picks up from the next, writing every row once', function (string $format) {
    $extra = TransferRunner::PAGE + 400;
    $app = manyWidgets($extra);
    expect($app->export(['format' => $format])->status)->toBe(202);

    $paused = runOnce($app, static fn (): bool => true);
    $record = $app->record();
    expect($paused)->toMatchArray(['rows' => TransferRunner::PAGE, 'key' => $record->key])
        ->and($record->status)->toBe(TransferRecord::RUNNING)
        ->and($record->rows)->toBe(TransferRunner::PAGE)
        ->and($app->storage->keys())->toBe([$record->key])
        // Held, not offered: a paused export has no link yet.
        ->and($record->isDownloadable($app->now))->toBeFalse();

    expect(runOnce($app, static fn (): bool => false))->toBeNull();
    $record = $app->record();
    $total = $extra + 3;
    expect($record->status)->toBe(TransferRecord::DONE)
        ->and($record->rows)->toBe($total)
        // The paused run's file gone, now part of the whole.
        ->and($app->storage->keys())->toBe([$record->key])
        ->and($app->queue->size(Transfers::QUEUE))->toBe(0);

    $lines = array_values(array_filter(preg_split('/\r?\n/', (string) $app->storage->contents($record->key)) ?: [], static fn (string $l): bool => $l !== ''));
    $skus = $format === 'csv'
        ? array_map(static fn (string $line): string => explode(',', $line)[0], array_slice($lines, 1))
        : array_map(static fn (string $line): string => json_decode($line, true)['sku'], $lines);
    if ($format === 'csv') {
        expect($lines[0])->toBe('sku,name,stock')
            ->and(array_filter($lines, static fn (string $l): bool => $l === 'sku,name,stock'))->toHaveCount(1);
    }
    $expected = ['A-1', 'B-2', 'LOCKED', ...array_map(static fn (int $i): string => sprintf('M-%05d', $i), range(0, $extra - 1))];
    sort($expected);
    expect($skus)->toBe($expected);
})->with(['csv', 'jsonl']);

it('counts the rows written before a pause against the most it may write', function () {
    $app = manyWidgets(1_000);
    $app->export();
    runOnce($app, static fn (): bool => true);
    runOnce($app, static fn (): bool => false);

    expect($app->record()->status)->toBe(TransferRecord::FAILED)
        ->and($app->record()->failure)->toContain('more than 1000 rows')
        ->and($app->record()->key)->toBe('')
        ->and($app->storage->keys())->toBe([]);
});

it('runs a paused export again in its own tenant only, with no other tenant\'s rows', function () {
    $app = manyWidgets(TransferRunner::PAGE + 1);
    $app->export();
    runOnce($app, static fn (): bool => true);
    runOnce($app, static fn (): bool => false);

    $csv = (string) $app->storage->contents($app->record()->key);
    expect($csv)->not->toContain('Globex')
        ->and($csv)->not->toContain('secret');
});

it('leaves an export stopped mid-page unclaimed, for the job handed back to run from the start', function () {
    $app = manyWidgets(TransferRunner::PAGE + 1);
    $app->export();

    expect(runOnce($app, static fn (): bool => throw new WorkerStopping('stopped')))->toBeNull()
        ->and($app->record()->status)->toBe(TransferRecord::QUEUED)
        ->and($app->storage->keys())->toBe([]);

    runOnce($app, static fn (): bool => false);
    expect($app->record()->status)->toBe(TransferRecord::DONE)
        ->and($app->record()->rows)->toBe(TransferRunner::PAGE + 4);
});

it('fails an export whose checkpoint names a file its record does not hold', function () {
    $app = manyWidgets(TransferRunner::PAGE + 1);
    $app->export();
    $checkpoint = runOnce($app, static fn (): bool => true);
    \assert($checkpoint !== null);

    // Sealed with the application's key -- what a forged one cannot be --
    // and still checked against the record: another file is not this one.
    $job = $app->queue->reserve([Transfers::QUEUE], $app->now, 60);
    \assert($job !== null);
    $envelope = $app->runner->open($job->payload);
    $other = new Envelope(
        $envelope->id,
        $envelope->class,
        $envelope->arguments,
        $envelope->tenant,
        $envelope->panel,
        $envelope->user,
        $envelope->epoch,
        $envelope->dispatchedAt,
        $envelope->declaredBy,
        [...$checkpoint, 'key' => 'exports/' . str_repeat('0', 32) . '.csv'],
        $envelope->releases,
    );
    $app->runner->run($other, $app->runner->control($other));

    expect($app->record()->status)->toBe(TransferRecord::FAILED)
        ->and($app->record()->failure)->toContain('could not be found')
        ->and($app->storage->keys())->toBe([]);
});
