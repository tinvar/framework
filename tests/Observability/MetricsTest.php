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

/** @param list<\Tinvar\Observability\MetricsStore> $readAlso */
/** @param (\Closure(): float)|null $clock */
function metrics(?\Tinvar\Observability\MetricsStore $store = null, float $flushEvery = 0.0, array $readAlso = [], ?\Closure $clock = null): Metrics
{
    return new Metrics($store ?? new ArrayMetricsStore(), $flushEvery, $readAlso, $clock)
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

// The server counts in APCu, which its queue worker cannot reach; the
// worker counts into a file, which the server reads beside its own.
it('adds what other processes counted to its own at a scrape, and writes only its own store', function () {
    $own = new ArrayMetricsStore();
    $shared = new ArrayMetricsStore();
    $worker = metrics($shared);
    $worker->increment('shop_orders_total', ['channel' => 'web'], 2);
    $worker->flush();
    $before = $shared->all();

    $server = metrics($own, readAlso: [$shared]);
    $server->increment('shop_orders_total', ['channel' => 'web']);
    $server->increment('shop_orders_total', ['channel' => 'app']);

    $text = $server->exposition();
    expect($text)->toContain('shop_orders_total{channel="web"} 3')
        ->and($text)->toContain('shop_orders_total{channel="app"} 1')
        ->and($shared->all())->toBe($before);
});

// The file other processes count into is read, not trusted: a series key
// that is not one Metrics writes -- a label set that would break the text
// format, or forge a line in it -- is skipped.
it('skips a series key read from a store that is not one it would write', function () {
    $shared = new class implements \Tinvar\Observability\MetricsStore {
        public function add(array $deltas): void {}

        public function all(): array
        {
            $s = "\x1f";

            return [
                "shop_orders_total{$s}{$s}channel=\"web\"{$s}" => 1_000_000,
                "shop_orders_total{$s}{$s}channel=\"web\"}\nshop_orders_total{channel=\"forged\"{$s}" => 9_000_000,
                "shop_orders_total{$s}{$s}channel=\"a\rb\"{$s}" => 1_000_000,
                "shop_orders_total{$s}{$s}other=\"web\"{$s}" => 1_000_000,
                "shop_orders_total{$s}{$s}channel=\"x\" ,y=\"z\"{$s}" => 1_000_000,
                "shop_orders_total{$s}bucket{$s}channel=\"web\"{$s}" => 1_000_000,
                "shop_checkout_seconds{$s}bucket{$s}channel=\"web\"{$s}nope" => 1_000_000,
                "shop_checkout_seconds{$s}count{$s}channel=\"web\"{$s}" => 2_000_000,
            ];
        }
    };

    $text = metrics(new ArrayMetricsStore(), readAlso: [$shared])->exposition();

    expect($text)->toContain('shop_orders_total{channel="web"} 1' . "\n")
        ->and($text)->toContain('shop_checkout_seconds_count{channel="web"} 2')
        ->and($text)->not->toContain('forged')
        ->and($text)->not->toContain("\r")
        ->and($text)->not->toContain('other=')
        ->and($text)->not->toContain('y="z"')
        ->and($text)->not->toContain('nope');
});

it('creates its file readable and writable by its owner alone, and refuses one others may write', function () {
    $directory = sys_get_temp_dir() . '/tinvar-metrics-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    try {
        $own = new SqliteMetricsStore("{$directory}/own.sqlite");
        $own->add(['k' => 1]);
        clearstatcache();
        $mode = fileperms("{$directory}/own.sqlite") & 0o777;

        touch("{$directory}/open.sqlite");
        chmod("{$directory}/open.sqlite", 0o666);

        expect($mode)->toBe(0o600)
            ->and(static fn () => new SqliteMetricsStore("{$directory}/open.sqlite")->all())->toThrow(\RuntimeException::class, 'writable');
    } finally {
        exec('rm -rf ' . escapeshellarg($directory));
    }
});

// SQLite reads its write-ahead log and journal beside the file as part of
// it: a sidecar someone else could write is a way in for a forged series.
it('refuses a sidecar others may write, and a directory anyone may write without the sticky bit', function (string $plant, ?int $dirMode) {
    $directory = sys_get_temp_dir() . '/tinvar-metrics-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    try {
        if ($plant !== '') {
            touch("{$directory}/m.sqlite{$plant}");
            chmod("{$directory}/m.sqlite{$plant}", 0o666);
        }
        if ($dirMode !== null) {
            chmod($directory, $dirMode);
        }

        expect(static fn () => new SqliteMetricsStore("{$directory}/m.sqlite")->all())->toThrow(\RuntimeException::class, 'writable');
    } finally {
        chmod($directory, 0700);
        exec('rm -rf ' . escapeshellarg($directory));
    }
})->with([
    'a write-ahead log' => ['-wal', null],
    'its shared memory' => ['-shm', null],
    'a journal' => ['-journal', null],
    'a directory anyone may write' => ['', 0o777],
]);

// Review: the umask was narrowed across the whole open, the wait for WAL
// included -- and it is the process's, every FrankenPHP thread's, so their
// new files were 0600 for as long. Only the file's own creation narrows it;
// SQLite gives the sidecars it creates the file's mode.
it('makes the file and its sidecars its owner\'s alone under a wide umask, and leaves the umask as it was', function () {
    $directory = sys_get_temp_dir() . '/tinvar-metrics-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $previous = umask(0);
    try {
        $store = new SqliteMetricsStore("{$directory}/m.sqlite");
        $store->add(['k' => 1]);
        $umaskAfter = umask();
        clearstatcache();
        $modes = array_map(static fn (string $f): int => fileperms($f) & 0o777, glob("{$directory}/m.sqlite*") ?: []);

        expect($umaskAfter)->toBe(0)
            ->and(\count($modes))->toBeGreaterThan(1)
            ->and(array_filter($modes, static fn (int $mode): bool => ($mode & 0o077) !== 0))->toBe([]);
    } finally {
        umask($previous);
        exec('rm -rf ' . escapeshellarg($directory));
    }
});

// /tmp is anyone's but sticky: no one else can replace a file in it, and
// what they plant is refused by its owner. A group's shared data directory
// is the group's to write, as an application's often is.
it('opens a file in a sticky directory anyone may write, or one its group may, with sidecars its owner\'s alone', function (int $dirMode) {
    $directory = sys_get_temp_dir() . '/tinvar-metrics-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    chmod($directory, $dirMode);
    try {
        $store = new SqliteMetricsStore("{$directory}/m.sqlite");
        $store->add(['k' => 1]);
        clearstatcache();
        $sidecars = array_map(static fn (string $f): int => fileperms($f) & 0o777, glob("{$directory}/m.sqlite-*") ?: []);

        expect($store->all())->toBe(['k' => 1])
            ->and($sidecars)->not->toBeEmpty()
            ->and(array_filter($sidecars, static fn (int $mode): bool => ($mode & 0o077) !== 0))->toBe([]);
    } finally {
        chmod($directory, 0700);
        exec('rm -rf ' . escapeshellarg($directory));
    }
})->with(['sticky, anyone\'s' => [0o1777], 'its group\'s' => [0o770]]);

it('writes what it can read when one of the stores it reads fails', function () {
    $failing = new class implements \Tinvar\Observability\MetricsStore {
        public function add(array $deltas): void {}

        public function all(): array
        {
            throw new \RuntimeException('unable to open database file');
        }
    };
    $reported = [];
    $metrics = metrics(new ArrayMetricsStore(), readAlso: [$failing]);
    $metrics->reportTo(static function (\Throwable $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });
    $metrics->increment('shop_orders_total', ['channel' => 'web']);

    expect($metrics->exposition())->toContain('shop_orders_total{channel="web"} 1')
        ->and($reported)->toBe(['unable to open database file']);
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

it('tries a store that fails at most once every flushEvery, not after every request', function () {
    $failing = new class implements \Tinvar\Observability\MetricsStore {
        public int $attempts = 0;

        public function add(array $deltas): void
        {
            $this->attempts++;

            throw new \RuntimeException('database is locked');
        }

        public function all(): array
        {
            return [];
        }
    };
    $reported = 0;
    // The test's clock, so a worker paused under load between two flushes
    // is not taken for the interval passing.
    $now = 1_800_000_000.0;
    $metrics = metrics($failing, flushEvery: 0.05, clock: static function () use (&$now): float {
        return $now;
    });
    $metrics->reportTo(static function () use (&$reported): void {
        $reported++;
    });

    // Two requests inside one interval: the store is tried, and reported, once.
    $metrics->increment('shop_orders_total', ['channel' => 'web']);
    $metrics->flush();
    $metrics->increment('shop_orders_total', ['channel' => 'web']);
    $metrics->flush();
    $triedWithinTheInterval = [$failing->attempts, $reported];

    // Once the interval has passed, the next request tries it again.
    $now += 0.06;
    $metrics->increment('shop_orders_total', ['channel' => 'web']);
    $metrics->flush();

    expect($triedWithinTheInterval)->toBe([1, 1])
        ->and([$failing->attempts, $reported])->toBe([2, 2]);
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
