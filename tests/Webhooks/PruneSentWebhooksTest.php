<?php

declare(strict_types=1);

/**
 * The events kept to be sent again are forgotten once a month old, a
 * bounded number at a time (ADR 0107), and the delivery log's tries beside
 * them (ADR 0116).
 */

namespace Tinvar\Tests\Webhooks\PruneSent;

use Tinvar\Attributes\Schedule;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Webhooks\ArrayDeliveryLog;
use Tinvar\Webhooks\DatabaseDeliveryLog;
use Tinvar\Webhooks\DeliveryAttempt;
use Tinvar\Webhooks\ArraySentWebhooks;
use Tinvar\Webhooks\PruneSentWebhooks;
use Tinvar\Webhooks\SentWebhook;

const NOW = 1_900_000_000;

function sent(string $delivery, int $sentAt): SentWebhook
{
    return new SentWebhook($delivery, 'acme', 'orders', 'order.placed', '{}', $sentAt);
}

it('forgets the events past a month, and keeps the rest', function () {
    $store = new ArraySentWebhooks();
    $store->keep(sent('old', NOW - PruneSentWebhooks::KEEP - 1));
    $store->keep(sent('recent', NOW - PruneSentWebhooks::KEEP + 60));

    expect(PruneSentWebhooks::prune($store, NOW))->toBe(1)
        ->and($store->find('acme', 'old'))->toBeNull()
        ->and($store->find('acme', 'recent'))->not->toBeNull();
});

it('stops after its most batches, leaving the rest to the next run', function () {
    $store = new ArraySentWebhooks();
    $most = PruneSentWebhooks::BATCH * PruneSentWebhooks::MOST_BATCHES;
    for ($i = 0; $i < $most + 5; $i++) {
        $store->keep(sent("d{$i}", NOW - PruneSentWebhooks::KEEP - 10));
    }

    expect(PruneSentWebhooks::prune($store, NOW))->toBe($most)
        ->and(PruneSentWebhooks::prune($store, NOW))->toBe(5);
});

it('is scheduled hourly', function () {
    $schedule = new \ReflectionClass(PruneSentWebhooks::class)->getAttributes(Schedule::class)[0]->newInstance();

    expect($schedule->expression)->toBe('41 * * * *');
});

it('forgets the delivery log\'s tries past a month beside the events, a bounded number at a time', function () {
    $log = new ArrayDeliveryLog();
    $most = PruneSentWebhooks::BATCH * PruneSentWebhooks::MOST_BATCHES;
    for ($i = 0; $i < $most + 3; $i++) {
        $log->record(attempt("d{$i}", NOW - PruneSentWebhooks::KEEP - 1));
    }
    $log->record(attempt('recent', NOW - PruneSentWebhooks::KEEP + 60));

    expect(PruneSentWebhooks::pruneLog($log, NOW))->toBe($most)
        ->and(PruneSentWebhooks::pruneLog($log, NOW))->toBe(3)
        ->and(array_map(static fn (DeliveryAttempt $a): string => $a->delivery, $log->recorded))->toBe(['recent']);
});

it('forgets old tries from the database log by the index it makes, every tenant\'s', function () {
    $connection = PdoConnection::sqlite(':memory:');
    DatabaseDeliveryLog::define(new Schema($connection, Dialect::for($connection)));
    $log = new DatabaseDeliveryLog($connection);
    $log->record(attempt('old', NOW - PruneSentWebhooks::KEEP - 1, 'acme'));
    $log->record(attempt('older', NOW - PruneSentWebhooks::KEEP - 99, 'globex'));
    $log->record(attempt('recent', NOW - 60, 'acme'));

    $indexes = array_column(Dialect::for($connection)->indexes($connection, 'webhook_deliveries'), 'name');

    expect($indexes)->toContain('webhook_deliveries_attempted')
        ->and($log->prune(NOW - PruneSentWebhooks::KEEP, 1))->toBe(1)
        ->and($log->attempts('older'))->toBe([])
        ->and(PruneSentWebhooks::pruneLog($log, NOW))->toBe(1)
        ->and($log->attempts('old'))->toBe([])
        ->and($log->recent('acme'))->toHaveCount(1);
});

function attempt(string $delivery, int $at, string $tenant = 'acme'): DeliveryAttempt
{
    return new DeliveryAttempt($delivery, 'orders', 'order.placed', $tenant, 1, 'delivered', 200, null, 12, $at);
}
