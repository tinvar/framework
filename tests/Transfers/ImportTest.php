<?php

declare(strict_types=1);

/**
 * Imports end to end, through the Kernel: a CSV taken at /_import, held to
 * what the import declares before anything is stored, and applied by the
 * worker as the user who sent it, in their tenant -- every row validated
 * through the form request, and all of them or none.
 */

use Tinvar\Tests\Transfers\Fixtures\App;
use Tinvar\Transfers\TransferRecord;
use Tinvar\Transfers\Transfers;

use function Tinvar\Tests\Transfers\Fixtures\json;

use const Tinvar\Tests\Transfers\Fixtures\G1;
use const Tinvar\Tests\Transfers\Fixtures\W1;
use const Tinvar\Tests\Transfers\Fixtures\W2;
use const Tinvar\Tests\Transfers\Fixtures\W3;

require_once __DIR__ . '/Fixtures/app.php';

/** Sends the file, works the queue, and answers the record. */
function imported(App $app, string $csv): TransferRecord
{
    $response = $app->import($csv);
    expect($response->status)->toBe(202);
    expect($app->work())->toBe([]);

    return $app->record();
}

describe('an import that applies', function () {
    it('applies every row through the form request, as the user, and keeps no file', function () {
        $app = new App();
        $record = imported($app, "widget,stock\r\n" . W1 . ",10\r\n" . W2 . ",20\r\n");

        expect($record->status)->toBe(TransferRecord::DONE)
            ->and($record->rows)->toBe(2)
            ->and($record->errorCount)->toBe(0)
            ->and($record->key)->toBe('')
            ->and($app->stockOf(W1))->toBe(10)
            ->and($app->stockOf(W2))->toBe(20)
            ->and($app->storage->keys())->toBe([])
            ->and($app->notes->finished[0]['link'])->toBeNull();
    });

    it('reads its columns in any order, past a byte-order mark and blank lines', function () {
        $app = new App();
        $record = imported($app, "\xEF\xBB\xBFstock,widget\n\n7," . W1 . "\n");

        expect($record->status)->toBe(TransferRecord::DONE)
            ->and($app->stockOf(W1))->toBe(7);
    });

    it('runs in the tenant it was sent in, whatever the worker last ran as', function () {
        $app = new App();
        expect($app->import("widget,stock\n" . G1 . ",99\n", headers: ['x-test-tenant' => 'globex'])->status)->toBe(202);
        $app->orm->switchTenant('acme');
        $app->work();

        expect($app->record()->status)->toBe(TransferRecord::DONE)
            ->and($app->stockOf(G1, 'globex'))->toBe(99);
    });
});

// ADR 0075: an import writes as an action does, and invalidates what it
// declares as an action's result does -- once its transaction commits.
describe('an import\'s declared tags', function () {
    it('are invalidated in its tenant when its rows are applied', function () {
        $app = new App();
        $before = $app->kernel->sharedTags?->stamp(['widgets'], 'acme');

        imported($app, "widget,stock\n" . W1 . ",10\n");

        expect($app->kernel->sharedTags?->stamp(['widgets'], 'acme'))->not->toBe($before);
    });

    it('are not, when the file is refused and nothing changed', function () {
        $app = new App();
        $before = $app->kernel->sharedTags?->stamp(['widgets'], 'acme');

        expect(imported($app, "widget,stock\n" . W1 . ",-1\n")->status)->toBe(TransferRecord::FAILED)
            ->and($app->kernel->sharedTags?->stamp(['widgets'], 'acme'))->toBe($before);
    });

    it('refuses a tag that is not one', function () {
        expect(fn () => new \Tinvar\Attributes\Import(revalidates: ['']))
            ->toThrow(\InvalidArgumentException::class, 'revalidates a tag that is not one');
    });
});

describe('an import that is refused row by row', function () {
    it('changes nothing when any row fails, and names each failing row and field', function () {
        $app = new App();
        $record = imported($app, "widget,stock\n" . W1 . ",10\n" . W2 . ",-1\n" . W1 . ",lots\n");

        expect($record->status)->toBe(TransferRecord::FAILED)
            ->and($record->errorCount)->toBe(2)
            ->and($record->errors)->toBe([
                ['row' => 3, 'field' => 'stock', 'message' => 'stock must be at least 0.'],
                ['row' => 4, 'field' => 'stock', 'message' => 'stock must be a whole number.'],
            ])
            ->and($record->failure)->toBe('2 rows were refused, so nothing was changed.')
            ->and($app->stockOf(W1))->toBe(3)
            ->and($app->storage->keys())->toBe([]);
    });

    it('resolves every model in the tenant: another tenant\'s id is a row that names nothing', function () {
        $app = new App();
        $record = imported($app, "widget,stock\n" . W1 . ",10\n" . G1 . ",0\n");

        expect($record->status)->toBe(TransferRecord::FAILED)
            ->and($record->errors)->toBe([['row' => 3, 'field' => 'widget', 'message' => 'widget does not match an existing record.']])
            ->and($app->stockOf(G1, 'globex'))->toBe(11)
            ->and($app->stockOf(W1))->toBe(3);
    });

    it('asks the #[Authorize] naming a row field of every row', function () {
        $app = new App();
        $record = imported($app, "widget,stock\n" . W3 . ",1\n");

        expect($record->status)->toBe(TransferRecord::FAILED)
            ->and($record->errors)->toBe([['row' => 2, 'field' => 'widget', 'message' => 'widget is not one you may use here.']])
            ->and($app->stockOf(W3))->toBe(7);
    });

    it('evaluates no formula: a cell is text the form request coerces', function () {
        $app = new App();
        $record = imported($app, "widget,stock\n" . W1 . ",=1+1\n" . W2 . ",\"@SUM(1,2)\"\n");

        expect($record->status)->toBe(TransferRecord::FAILED)
            ->and(array_column($record->errors, 'message'))->toBe(['stock must be a whole number.', 'stock must be a whole number.'])
            ->and($app->stockOf(W1))->toBe(3);
    });

    it('refuses a row with more or fewer cells than the header names', function () {
        $app = new App();
        $record = imported($app, "widget,stock\n" . W1 . ",1,extra\n");

        expect($record->status)->toBe(TransferRecord::FAILED)
            ->and($record->errors[0]['row'])->toBe(2)
            ->and($record->errors[0]['message'])->toContain('cells');
    });
});

describe('/_import', function () {
    it('refuses a user its #[Authorize] denies, and stores nothing', function () {
        $app = new App();

        expect($app->import("widget,stock\n" . W1 . ",1\n", user: 'customer-1')->status)->toBe(403)
            ->and($app->storage->keys())->toBe([])
            ->and($app->store->all())->toBe([]);
    });

    it('refuses an oversized file before reading it', function () {
        $app = new App();
        $response = $app->import("widget,stock\n" . str_repeat(W1 . ",1\n", 200));

        expect($response->status)->toBe(413)
            ->and(json($response)['error']['code'])->toBe('import_too_large')
            ->and($app->storage->keys())->toBe([]);
    });

    it('refuses more rows than it takes', function () {
        $app = new App();
        $response = $app->import("widget,stock\n" . str_repeat(W1 . ",1\n", 6));

        expect($response->status)->toBe(422)
            ->and(json($response)['error']['code'])->toBe('import_too_many_rows')
            ->and($app->storage->keys())->toBe([]);
    });

    it('refuses a file that is not UTF-8 text', function (string $csv) {
        $app = new App();
        $response = $app->import($csv);

        expect($response->status)->toBe(422)
            ->and(json($response)['error']['code'])->toBe('csv_encoding')
            ->and($app->storage->keys())->toBe([]);
    })->with([
        'Latin-1' => ["widget,stock\n" . W1 . ",1\nCaf\xE9,2\n"],
        'a NUL byte' => ["widget,stock\n" . W1 . ",1\x00\n"],
        'a PNG' => ["\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR"],
    ]);

    it('refuses a header that is not its row\'s columns', function (string $header) {
        $app = new App();
        $response = $app->import("{$header}\n" . W1 . ",1\n");

        expect($response->status)->toBe(422)
            ->and(json($response)['error']['code'])->toBe('import_header');
    })->with([
        'an unknown column' => ['widget,secret'],
        'a column twice' => ['widget,widget'],
        'a required column missing' => ['widget'],
    ]);

    it('refuses an empty file, a missing one, and one that is not multipart', function () {
        $app = new App();

        expect($app->import('')->status)->toBe(422)
            ->and($app->import('x', error: UPLOAD_ERR_NO_FILE)->status)->toBe(422)
            ->and($app->import('x', headers: ['content-type' => 'text/csv'])->status)->toBe(415)
            ->and($app->store->all())->toBe([]);
    });

    it('applies nothing once the user\'s epoch has moved, and prunes the file it was sent', function () {
        $app = new App();
        expect($app->import("widget,stock\n" . W1 . ",10\n")->status)->toBe(202);
        $app->users->epochs['staff-1'] = 'e2';

        expect($app->work())->toBe(['epoch_changed'])
            ->and($app->stockOf(W1))->toBe(3)
            ->and($app->storage->keys())->toHaveCount(1);

        $app->now += 86_400;
        expect($app->transfers->prune())->toBe(1)
            ->and($app->storage->keys())->toBe([]);
    });

    it('leaves nothing on the queue once it has run', function () {
        $app = new App();
        imported($app, "widget,stock\n" . W1 . ",10\n");

        expect($app->queue->size(Transfers::QUEUE))->toBe(0);
    });
});
