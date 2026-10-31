<?php

declare(strict_types=1);

/**
 * Counts kept in APCu, shared by every worker thread of one process -- and
 * what is not counted when APCu will not take it is said, and kept for the
 * next flush, never lost in silence nor counted twice.
 */

namespace Tinvar\Tests\Observability\ApcuMetricsStore;

use Tinvar\Observability\ApcuMetricsStore;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\MetricsNotStored;
use Tinvar\Observability\MetricsStore;

function apcu(): string
{
    if (!ApcuMetricsStore::available()) {
        test()->markTestSkipped('APCu is not loaded, or not enabled for the CLI (apc.enable_cli).');
    }

    return 'tinvar:test:metrics:' . bin2hex(random_bytes(4)) . ':';
}

it('adds to a series, making it the first time', function () {
    $store = new ApcuMetricsStore(apcu());

    $store->add(['a' => 5, 'b' => 1_000_000]);
    $store->add(['a' => 7]);

    expect($store->all())->toEqualCanonicalizing(['a' => 12, 'b' => 1_000_000]);
});

// apcu_inc() on a value that is not an integer fails, and so does adding a
// key that is there: the count was lost, and the caller never knew.
it('says which series it could not count, having counted the rest', function () {
    $prefix = apcu();
    $store = new ApcuMetricsStore($prefix);
    apcu_store("{$prefix}broken", 'not a number');

    try {
        $store->add(['ok' => 3, 'broken' => 4]);
        $thrown = null;
    } catch (MetricsNotStored $e) {
        $thrown = $e;
    }

    expect($thrown?->unwritten)->toBe(['broken' => 4])
        ->and($store->all()['ok'] ?? null)->toBe(3);
});

// The Metrics side: a store that counted some of a flush keeps only the rest
// for the next, so nothing it took is counted twice.
it('keeps only what a store could not count for the next flush', function () {
    $store = new class implements MetricsStore {
        /** @var list<array<string, int>> */
        public array $calls = [];

        public bool $fail = true;

        public function add(array $deltas): void
        {
            $this->calls[] = $deltas;
            if ($this->fail) {
                $this->fail = false;
                $unwritten = \array_slice($deltas, 0, 1, true);

                throw new MetricsNotStored('APCu would not count one series.', $unwritten);
            }
        }

        public function all(): array
        {
            return [];
        }
    };
    $reported = [];
    $metrics = new Metrics(store: $store, flushEvery: 0.0);
    $metrics->reportTo(static function (\Throwable $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });
    $metrics->counter('first_total', 'First.')->counter('second_total', 'Second.');
    $metrics->increment('first_total');
    $metrics->increment('second_total');
    $metrics->flush(true);
    $metrics->flush(true);

    expect($reported)->toBe(['APCu would not count one series.'])
        ->and($store->calls)->toHaveCount(2)
        ->and(\count($store->calls[0]))->toBeGreaterThan(1)
        ->and($store->calls[1])->toBe(\array_slice($store->calls[0], 0, 1, true));
});
