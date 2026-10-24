<?php

declare(strict_types=1);

/**
 * Metrics (ADR 0041): counters and histograms counted in memory, added to a
 * store when flushed, and written in Prometheus's text format with what the
 * collectors read at scrape time.
 */

namespace Tinvar\Tests\Observability\Metrics;

use Tinvar\Observability\ArrayMetricsStore;
use Tinvar\Observability\Collector;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\QueueDepth;
use Tinvar\Observability\SqliteMetricsStore;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\PendingJob;

function metrics(?\Tinvar\Observability\MetricsStore $store = null, float $flushEvery = 0.0): Metrics
{
    return new Metrics($store ?? new ArrayMetricsStore(), $flushEvery)
        ->counter('shop_orders_total', 'Orders placed.', ['channel'])
        ->histogram('shop_checkout_seconds', 'Seconds a checkout took.', ['channel'], [0.1, 0.5, 1.0]);
}

it('writes a counter and a histogram in the text format, buckets cumulative and in order', function () {
    $metrics = metrics();
    $metrics->increment('shop_orders_total', ['channel' => 'web']);
    $metrics->increment('shop_orders_total', ['channel' => 'web'], 2);
    $metrics->observe('shop_checkout_seconds', 0.05, ['channel' => 'web']);
    $metrics->observe('shop_checkout_seconds', 0.7, ['channel' => 'web']);
    $metrics->observe('shop_checkout_seconds', 3.0, ['channel' => 'web']);

    expect($metrics->exposition())->toBe(implode("\n", [
        '# HELP shop_orders_total Orders placed.',
        '# TYPE shop_orders_total counter',
        'shop_orders_total{channel="web"} 3',
        '# HELP shop_checkout_seconds Seconds a checkout took.',
        '# TYPE shop_checkout_seconds histogram',
        'shop_checkout_seconds_bucket{channel="web",le="0.1"} 1',
        'shop_checkout_seconds_bucket{channel="web",le="0.5"} 1',
        'shop_checkout_seconds_bucket{channel="web",le="1"} 2',
        'shop_checkout_seconds_bucket{channel="web",le="+Inf"} 3',
        'shop_checkout_seconds_sum{channel="web"} 3.75',
        'shop_checkout_seconds_count{channel="web"} 3',
    ]) . "\n");
});

it('escapes a label value, and keeps control characters out of it', function () {
    $metrics = metrics();
    $metrics->increment('shop_orders_total', ['channel' => "a\"b\\c\nd\x1fe"]);

    expect($metrics->exposition())->toContain('shop_orders_total{channel="a\"b\\\\c?d?e"} 1');
});

it('refuses a metric nobody defined, and labels that are not the ones declared', function (\Closure $call) {
    $call(metrics());
})->with([
    'undefined' => [static fn (Metrics $m) => $m->increment('nope_total')],
    'missing label' => [static fn (Metrics $m) => $m->increment('shop_orders_total')],
    'extra label' => [static fn (Metrics $m) => $m->increment('shop_orders_total', ['channel' => 'web', 'path' => '/x'])],
    'wrong type' => [static fn (Metrics $m) => $m->observe('shop_orders_total', 1.0, ['channel' => 'web'])],
])->throws(\LogicException::class);

it('refuses a counter going down, and bad names', function (\Closure $call) {
    $call(metrics());
})->with([
    'down' => [static fn (Metrics $m) => $m->increment('shop_orders_total', ['channel' => 'web'], -1)],
    'name' => [static fn (Metrics $m) => $m->counter('shop orders', 'x')],
    'label' => [static fn (Metrics $m) => $m->counter('x_total', 'x', ['__reserved'])],
    'le' => [static fn (Metrics $m) => $m->histogram('x_seconds', 'x', ['le'])],
    'buckets' => [static fn (Metrics $m) => $m->histogram('x_seconds', 'x', [], [1.0, 0.5])],
])->throws(\InvalidArgumentException::class);

it('lets the same definition be made twice, and not a different one', function () {
    $metrics = metrics();
    $metrics->counter('shop_orders_total', 'Orders placed.', ['channel']);

    expect(static fn () => $metrics->counter('shop_orders_total', 'Orders.', ['channel']))->toThrow(\LogicException::class);
});

// A label value the caller let grow -- a path instead of a route -- costs
// one more series, not one per request.
it('files label sets past the cap under _other', function () {
    $metrics = metrics();
    for ($i = 0; $i < Metrics::MAX_SERIES + 25; $i++) {
        $metrics->increment('shop_orders_total', ['channel' => "c{$i}"]);
    }

    $text = $metrics->exposition();
    expect(substr_count($text, 'shop_orders_total{'))->toBe(Metrics::MAX_SERIES + 1)
        ->and($text)->toContain('shop_orders_total{channel="_other"} 25');
});

it('holds counts until the interval passes, and flushes them when forced', function () {
    $store = new ArrayMetricsStore();
    $metrics = metrics($store, flushEvery: 3600.0);
    $metrics->increment('shop_orders_total', ['channel' => 'web']);

    $metrics->flush();
    $first = $store->all();
    $metrics->flush(true);

    expect($first)->not->toBe([])
        ->and($store->all())->toBe($first);

    $metrics->increment('shop_orders_total', ['channel' => 'web']);
    $metrics->flush();

    expect(array_sum($store->all()))->toBe(1_000_000);
    $metrics->flush(true);
    expect(array_sum($store->all()))->toBe(2_000_000);
});

it('adds what several processes counted in one SQLite file', function () {
    $path = sys_get_temp_dir() . '/tinvar-metrics-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $a = metrics(new SqliteMetricsStore($path));
        $b = metrics(new SqliteMetricsStore($path));
        $a->increment('shop_orders_total', ['channel' => 'web']);
        $b->increment('shop_orders_total', ['channel' => 'web'], 4);
        $b->observe('shop_checkout_seconds', 0.25, ['channel' => 'app']);
        $a->flush();
        $b->flush();

        $text = metrics(new SqliteMetricsStore($path))->exposition();
        expect($text)->toContain('shop_orders_total{channel="web"} 5')
            ->and($text)->toContain('shop_checkout_seconds_sum{channel="app"} 0.25')
            ->and($text)->toContain('shop_checkout_seconds_bucket{channel="app",le="0.1"} 0');
    } finally {
        foreach (glob("{$path}*") ?: [] as $file) {
            @unlink($file);
        }
    }
});

it('reports a store that fails, keeps the counts, and still writes the rest', function () {
    $failing = new class implements \Tinvar\Observability\MetricsStore {
        public bool $fail = true;

        /** @var array<string, int> */
        public array $values = [];

        public function add(array $deltas): void
        {
            if ($this->fail) {
                throw new \RuntimeException('database is locked');
            }
            foreach ($deltas as $key => $delta) {
                $this->values[$key] = ($this->values[$key] ?? 0) + $delta;
            }
        }

        public function all(): array
        {
            return $this->values;
        }
    };
    $reported = [];
    $metrics = metrics($failing);
    $metrics->reportTo(static function (\Throwable $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });

    $metrics->increment('shop_orders_total', ['channel' => 'web']);
    $metrics->flush();
    $failing->fail = false;
    $metrics->flush();

    expect($reported)->toBe(['database is locked'])
        ->and(array_sum($failing->values))->toBe(1_000_000);
});

it('writes each collector\'s gauges and counters, and skips one that fails or clashes', function () {
    $metrics = metrics();
    $metrics->collect(new class implements Collector {
        public function collect(): array
        {
            return [
                ['name' => 'shop_open_carts', 'help' => 'Carts open now.', 'value' => 12],
                ['name' => 'shop_open_carts', 'help' => 'Carts open now.', 'labels' => ['region' => 'eu'], 'value' => 3],
                ['name' => 'shop_carts_total', 'help' => 'Carts ever opened.', 'type' => 'counter', 'value' => 99],
                // A family of that name exists already: refused, not duplicated.
                ['name' => 'shop_orders_total', 'help' => 'clash', 'value' => 1],
                ['name' => 'not a name', 'help' => 'x', 'value' => 1],
            ];
        }
    });
    $metrics->collect(new class implements Collector {
        public function collect(): array
        {
            throw new \RuntimeException('down');
        }
    });

    $text = $metrics->exposition();

    expect($text)->toContain("# TYPE shop_open_carts gauge\nshop_open_carts 12\nshop_open_carts{region=\"eu\"} 3")
        ->and($text)->toContain("# TYPE shop_carts_total counter\nshop_carts_total 99")
        ->and(substr_count($text, '# TYPE shop_orders_total'))->toBe(1)
        ->and($text)->not->toContain('clash')
        ->and($text)->not->toContain('not a name');
});

it('reports each queue\'s depth', function () {
    $queue = new ArrayQueue();
    $queue->push(new PendingJob('j1', 'mail', 'payload-1', 0));
    $queue->push(new PendingJob('j2', 'mail', 'payload-2', 0));
    $queue->push(new PendingJob('j3', 'default', 'payload-3', 0));

    $metrics = new Metrics()->collect(new QueueDepth($queue, ['mail', 'default', 'reports']));

    expect($metrics->exposition())->toContain(implode("\n", [
        '# TYPE tinvar_queue_depth gauge',
        'tinvar_queue_depth{queue="mail"} 2',
        'tinvar_queue_depth{queue="default"} 1',
        'tinvar_queue_depth{queue="reports"} 0',
    ]));
});
