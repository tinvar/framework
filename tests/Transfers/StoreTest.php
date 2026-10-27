<?php

declare(strict_types=1);

/**
 * The record of each transfer, in a table: found only by the context it was
 * started in, and pruned by age.
 */

use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Transfers\ArrayTransferStore;
use Tinvar\Transfers\CompiledTransfer;
use Tinvar\Transfers\DatabaseTransferStore;
use Tinvar\Transfers\TransferRecord;
use Tinvar\Transfers\TransferStore;

function transferStores(): array
{
    $connection = PdoConnection::sqlite(':memory:', 'test');
    DatabaseTransferStore::define(new Schema($connection, Dialect::for($connection)));

    return ['database' => new DatabaseTransferStore($connection), 'memory' => new ArrayTransferStore()];
}

function transferRecord(string $id, string $tenant = 'acme', string $user = 'u-1', int $createdAt = 1_000): TransferRecord
{
    return new TransferRecord($id, CompiledTransfer::EXPORT, 'widgets', 'csv', $tenant, 'staff', $user, TransferRecord::QUEUED, $createdAt);
}

it('finds a record only in the tenant, panel and for the user it was started by', function () {
    foreach (transferStores() as $store) {
        \assert($store instanceof TransferStore);
        $store->add(transferRecord('t-1'));

        expect($store->find('t-1', 'acme', 'staff', 'u-1')?->id)->toBe('t-1')
            ->and($store->find('t-1', 'globex', 'staff', 'u-1'))->toBeNull()
            ->and($store->find('t-1', 'acme', '', 'u-1'))->toBeNull()
            ->and($store->find('t-1', 'acme', 'staff', 'u-2'))->toBeNull();
    }
});

it('keeps what an update writes, errors included, and lists the newest first', function () {
    foreach (transferStores() as $store) {
        \assert($store instanceof TransferStore);
        $store->add(transferRecord('t-1', createdAt: 1_000));
        $store->add(transferRecord('t-2', createdAt: 2_000));
        $store->add(transferRecord('t-3', user: 'u-2', createdAt: 3_000));
        $store->update(transferRecord('t-1')->with([
            'status' => TransferRecord::FAILED,
            'errorCount' => 1,
            'errors' => [['row' => 2, 'field' => 'stock', 'message' => 'stock must be at least 0.']],
            'failure' => 'One row was refused, so nothing was changed.',
            'finishedAt' => 1_500,
        ]));

        $mine = $store->recent('acme', 'staff', 'u-1', 10);
        expect(array_map(static fn (TransferRecord $r): string => $r->id, $mine))->toBe(['t-2', 't-1'])
            ->and($mine[1]->errors)->toBe([['row' => 2, 'field' => 'stock', 'message' => 'stock must be at least 0.']])
            ->and($mine[1]->finishedAt)->toBe(1_500)
            ->and($mine[1]->status)->toBe(TransferRecord::FAILED);
    }
});

it('lets one claim a queued record, once', function () {
    foreach (transferStores() as $store) {
        \assert($store instanceof TransferStore);
        $store->add(transferRecord('t-1'));

        expect($store->claim('t-1'))->toBeTrue()
            ->and($store->claim('t-1'))->toBeFalse()
            ->and($store->claim('nope'))->toBeFalse()
            ->and($store->find('t-1', 'acme', 'staff', 'u-1')?->status)->toBe(TransferRecord::RUNNING);
    }
});

it('names what has expired, or is older than records are kept', function () {
    foreach (transferStores() as $store) {
        \assert($store instanceof TransferStore);
        $store->add(transferRecord('old', createdAt: 100));
        $store->add(transferRecord('expired', createdAt: 5_000)->with(['expiresAt' => 6_000]));
        $store->add(transferRecord('kept', createdAt: 5_000)->with(['expiresAt' => 9_000]));

        expect(array_map(static fn (TransferRecord $r): string => $r->id, $store->expired(7_000, 1_000, 10)))->toBe(['old', 'expired']);
        $store->forget('old');
        expect($store->find('old', 'acme', 'staff', 'u-1'))->toBeNull();
    }
});
