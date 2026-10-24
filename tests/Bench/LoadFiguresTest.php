<?php

declare(strict_types=1);

/**
 * The load benchmark's arithmetic: CPU time as ps writes it, and the
 * figures read out of a k6 summary. A wrong parse here does not fail
 * loudly -- it prints a plausible number -- so it is pinned.
 */

namespace Tinvar\Tests\Bench\LoadFigures;

use Tinvar\Bench\Load;
use Tinvar\Bench\LoadRun;

it('reads CPU time as macOS and procps write it', function () {
    $times = Load::cpuTimesFrom(implode("\n", [
        '  301   0:30.05',
        '  302  12:01.50',
        '  303 01:02:03',
        '  304 2-00:00:01',
        'garbage',
    ]));

    expect($times)->toBe([
        301 => 30.05,
        302 => 721.5,
        303 => 3723.0,
        304 => 172801.0,
    ]);
});

it('reads throughput, latency and failures out of a k6 summary', function () {
    $figures = LoadRun::k6Figures(['metrics' => [
        'http_reqs' => ['count' => 9126, 'rate' => 456.2963],
        'http_req_duration' => ['avg' => 140.1, 'med' => 139.98, 'p(95)' => 152.004, 'p(99)' => 159.4, 'max' => 201.7],
        'checks' => ['passes' => 18250, 'fails' => 2],
        'http_req_failed' => ['passes' => 1, 'fails' => 9125],
    ]]);

    expect($figures)->toBe([
        'requests' => 9126,
        'perSecond' => 456.3,
        'ms' => ['avg' => 140.1, 'p50' => 139.98, 'p95' => 152.0, 'p99' => 159.4, 'max' => 201.7],
        'checksFailed' => 2,
        'httpFailed' => 1,
    ]);
});

it('reads a summary missing a metric as zero rather than failing', function () {
    expect(LoadRun::k6Figures(['metrics' => []])['perSecond'])->toBe(0.0)
        ->and(LoadRun::k6Figures([])['requests'])->toBe(0);
});
