<?php

declare(strict_types=1);

/**
 * SqliteThrottleStore: counters in a file, shared by every process that
 * opens it -- what php -S needs, where each request starts from nothing.
 */

namespace Tinvar\Tests\Http\SqliteThrottleStore;

use Tinvar\Http\SqliteThrottleStore;

function scratchFile(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-throttle-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);

    return "{$directory}/throttle.sqlite";
}

function removeScratch(string $path): void
{
    foreach (glob(\dirname($path) . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir(\dirname($path));
}

it('counts hits in a window aligned to the clock, and starts again in the next', function () {
    $now = 1_000_030;
    $store = new SqliteThrottleStore(':memory:', static function () use (&$now): int {
        return $now;
    });

    expect($store->increment('k', 60))->toBe([1, 1_000_080])
        ->and($store->increment('k', 60))->toBe([2, 1_000_080])
        ->and($store->increment('other', 60))->toBe([1, 1_000_080])
        ->and($store->increment('k', 900)[0])->toBe(1, 'the same key over another window is another count');

    $now += 60;
    expect($store->increment('k', 60))->toBe([1, 1_000_140]);
});

// The point of the store: each php -S request builds its own, and they must
// all count against the one budget.
it('shares its counts with every store that opens the same file', function () {
    $path = scratchFile();

    try {
        new SqliteThrottleStore($path)->increment('k', 60);
        new SqliteThrottleStore($path)->increment('k', 60);

        expect(new SqliteThrottleStore($path)->increment('k', 60)[0])->toBe(3);
    } finally {
        removeScratch($path);
    }
});

it('takes back hits in the current window, never below zero and never opening one', function () {
    $now = 1_000_000;
    $store = new SqliteThrottleStore(':memory:', static function () use (&$now): int {
        return $now;
    });

    $store->increment('k', 60);
    $store->increment('k', 60);
    $store->release('k', 60);
    expect($store->increment('k', 60)[0])->toBe(2);

    $store->release('k', 60, PHP_INT_MAX);
    expect($store->increment('k', 60)[0])->toBe(1, 'cleared');

    $store->release('never-counted', 60);
    expect($store->increment('never-counted', 60)[0])->toBe(1);

    $now += 60;
    $store->release('k', 60);
    expect($store->increment('k', 60)[0])->toBe(1, 'a passed window gives nothing back to the next');
});

// Every new window makes a row; the hit that makes one clears a few that have
// passed, so the file cannot grow with every caller ever seen.
it('sweeps passed windows a bounded batch at a time', function () {
    $now = 1_000_000;
    $store = new SqliteThrottleStore(':memory:', static function () use (&$now): int {
        return $now;
    });
    for ($i = 0; $i < SqliteThrottleStore::SWEEP_BATCH * 2; $i++) {
        $store->increment("caller-{$i}", 60);
    }

    $now += 120;
    $store->increment('next', 60);
    expect($store->count())->toBe(SqliteThrottleStore::SWEEP_BATCH + 1);

    $store->increment('another', 60);
    $store->increment('another', 60);
    expect($store->count())->toBe(2, 'swept by the new window, and not by a second hit in it');
});

it('keeps the caller\'s key out of the file', function () {
    $path = scratchFile();

    try {
        new SqliteThrottleStore($path)->increment('actions|ip|203.0.113.7', 60);

        $written = implode('', array_map(file_get_contents(...), glob("{$path}*") ?: []));

        expect($written)->toContain('throttle')->not->toContain('203.0.113.7');
    } finally {
        removeScratch($path);
    }
});

it('opens nothing until it counts', function () {
    $path = scratchFile();

    try {
        new SqliteThrottleStore($path);

        expect(file_exists($path))->toBeFalse();
    } finally {
        removeScratch($path);
    }
});

it('refuses an empty path', function () {
    expect(fn () => new SqliteThrottleStore(''))->toThrow(\InvalidArgumentException::class);
});

// Atomic, or the limit is advisory: processes counting one key at once must
// between them see every count from 1 to N exactly once.
it('counts every hit once across processes counting at the same moment', function () {
    $path = scratchFile();
    $processes = 6;
    $each = 40;
    $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
    $script = \dirname($path) . '/count.php';
    file_put_contents($script, <<<'PHP'
        <?php
        require $argv[1];
        $store = new Tinvar\Http\SqliteThrottleStore($argv[2]);
        while (microtime(true) < (float) $argv[3]) {
            usleep(1000);
        }
        $counts = [];
        for ($i = 0; $i < (int) $argv[4]; $i++) {
            $counts[] = $store->increment('shared', 3600)[0];
        }
        echo json_encode($counts);
        PHP);

    try {
        // Started together, after every process has booted.
        $start = (string) (microtime(true) + 1.5);
        $running = [];
        for ($p = 0; $p < $processes; $p++) {
            $running[] = proc_open(
                [PHP_BINARY, $script, $autoload, $path, $start, (string) $each],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            ) ?: throw new \RuntimeException('Could not start a counting process.');
            $outputs[] = $pipes;
        }

        $counts = [];
        foreach ($running as $p => $process) {
            $out = stream_get_contents($outputs[$p][1]);
            $err = stream_get_contents($outputs[$p][2]);
            proc_close($process);
            expect($err)->toBe('');
            array_push($counts, ...json_decode((string) $out, true, flags: JSON_THROW_ON_ERROR));
        }
        sort($counts);

        expect($counts)->toBe(range(1, $processes * $each));
    } finally {
        removeScratch($path);
    }
});
