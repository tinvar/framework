<?php

declare(strict_types=1);

/**
 * The events kept to be sent again are forgotten once a month old, a
 * bounded number at a time (ADR 0107).
 */

namespace Tinvar\Tests\Webhooks\PruneSent;

use Tinvar\Attributes\Schedule;
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
