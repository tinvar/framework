<?php

declare(strict_types=1);

/**
 * The load test's arithmetic and its regression gate, without k6: how a
 * ceiling is read out of a run's steps, how a run is held to the baseline,
 * and the parsing a wrong answer from would print a plausible number rather
 * than fail.
 */

namespace Tinvar\Tests\Bench\LoadGate;

use Tinvar\Bench\Load\Analysis;
use Tinvar\Bench\Load\Gate;
use Tinvar\Bench\Load\LoadCommand;
use Tinvar\Bench\Load\Options;
use Tinvar\Bench\Load\Sampler;
use Tinvar\Bench\Load\Scaling;
use Tinvar\Bench\Load\Summary;

/**
 * A results file with one configuration and its classes' ceilings and costs.
 *
 * @param array<string, array{0: float|null, 1?: float, 2?: float}> $classes class => [ceiling, php_ms, renderer_ms]
 * @return array<string, mixed>
 */
function results(array $classes, int $workers = 10, int $renderers = 4, string $model = 'Apple M4'): array
{
    $ceilings = [];
    foreach ($classes as $class => $figures) {
        $ceilings[$class] = [
            'ceiling' => $figures[0],
            'ceiling_requests_per_s' => $figures[0],
            'reached' => true,
            'cost' => ['php_ms' => $figures[1] ?? 1.0, 'renderer_ms' => $figures[2] ?? 2.0, 'server_ms' => ($figures[1] ?? 1.0) + ($figures[2] ?? 2.0)],
            'steps' => [],
        ];
    }

    return [
        'date' => '2026-10-25',
        'machine' => ['model' => $model, 'cores' => 10],
        'settings' => ['step_seconds' => 24],
        'runs' => [['workers' => $workers, 'renderers' => $renderers, 'ceilings' => $ceilings]],
    ];
}

/** @return array<string, mixed> */
function baseline(): array
{
    return Gate::baselineFrom(results(['document' => [800.0, 1.0, 2.0], 'static' => [9000.0, 0.02, 0.0]]), 'bench/results/x.json', 0.15);
}

describe('the gate', function () {
    it('passes a run that measured what the baseline did', function () {
        $gate = Gate::compare(results(['document' => [800.0, 1.0, 2.0], 'static' => [9000.0, 0.02, 0.0]]), baseline(), 0.15);

        expect($gate['regressions'])->toBe([])
            ->and($gate['warnings'])->toBe([]);
    });

    it('passes a ceiling one step below the baseline and fails one two steps below', function () {
        // The gate's steps are the baseline's ceiling times 1.1^k.
        $oneStep = round(800 / 1.1);
        $twoSteps = round(800 / 1.1 ** 2);

        expect(Gate::compare(results(['document' => [$oneStep, 1.0, 2.0]]), baseline(), 0.15)['regressions'])->toBe([])
            ->and(Gate::compare(results(['document' => [$twoSteps, 1.0, 2.0]]), baseline(), 0.15)['regressions'])
            ->toHaveCount(1)
            ->and(Gate::compare(results(['document' => [$twoSteps, 1.0, 2.0]]), baseline(), 0.15)['regressions'][0])
            ->toContain('document ceiling 661, below 680');
    });

    it('fails a class whose first step already failed', function () {
        $gate = Gate::compare(results(['document' => [null]]), baseline(), 0.15);

        expect($gate['regressions'][0])->toContain('not found (the first step failed)');
    });

    it('fails a cost that rose past the tolerance, and ignores one too small to compare', function () {
        $gate = Gate::compare(results(['document' => [800.0, 1.2, 2.0], 'static' => [9000.0, 0.04, 0.0]]), baseline(), 0.15);

        expect($gate['regressions'])->toHaveCount(1)
            ->and($gate['regressions'][0])->toContain('document php_ms 1.200 core-ms per request, above 1.150');
    });

    it('fails a configuration the run did not measure, and warns of a class it left out', function () {
        expect(Gate::compare(results(['document' => [800.0]], renderers: 2), baseline(), 0.15)['regressions'][0])
            ->toBe('10 workers, 4 renderers: not measured in this run.');

        $partial = Gate::compare(results(['document' => [800.0, 1.0, 2.0]]), baseline(), 0.15);
        expect($partial['regressions'])->toBe([])
            ->and($partial['warnings'])->toBe(['10 workers, 4 renderers: static was not measured in this run, so it was not compared.']);
    });

    it('warns when the baseline came from another machine', function () {
        $gate = Gate::compare(results(['document' => [800.0, 1.0, 2.0], 'static' => [9000.0, 0.02, 0.0]], model: 'Apple M1'), baseline(), 0.15);

        expect($gate['warnings'])->toContain('The baseline was recorded on a different machine; its numbers do not carry over.');
    });

    it('fails an empty baseline rather than passing everything', function () {
        expect(Gate::compare(results(['document' => [800.0]]), ['configs' => []], 0.15)['regressions'])
            ->toBe(['The baseline lists no configuration to compare with.']);
    });

    it('records only the configurations asked for', function () {
        $results = results(['document' => [800.0]]);
        $results['runs'][] = ['workers' => 10, 'renderers' => 8, 'ceilings' => results(['document' => [900.0]])['runs'][0]['ceilings']];

        $kept = Gate::baselineFrom($results, 'x.json', 0.15, [['workers' => 10, 'renderers' => 8]]);

        expect($kept['configs'])->toHaveCount(1)
            ->and($kept['configs'][0]['renderers'])->toBe(8)
            ->and($kept['configs'][0]['classes']['document']['ceiling'])->toBe(900.0)
            ->and($kept['tolerance'])->toBe(0.15)
            ->and($kept['step_seconds'])->toBe(24);
    });

    it('steps from four below the baseline ceiling to three above, landing on it', function () {
        expect(Gate::rates(800.0))->toBe([546, 601, 661, 727, 800, 880, 968, 1065]);
    });
});

/**
 * A ceiling run's k6 summary, one step per entry, each judged on document
 * latency: [target, delivered, p95, failed share].
 *
 * @param list<array{0: int, 1: int, 2: float, 3: float}> $steps
 * @return array{status: int, started: float, ended: float, summary: array<string, mixed>}
 */
function ceilingRun(array $steps): array
{
    $t0 = 1_000_000.0;
    $metrics = [];
    foreach ($steps as $i => [$target, $delivered, $p95, $failed]) {
        $n = $i + 1;
        // 25 s held, 5 of them settling: 20 s measured.
        $metrics["iterations{step:{$n}}"] = ['values' => ['count' => $delivered * 20]];
        $metrics["http_reqs{step:{$n}}"] = ['values' => ['count' => $delivered * 20]];
        $metrics["http_req_failed{step:{$n}}"] = ['values' => ['rate' => $failed]];
        $metrics["http_reqs{step:{$n},class:document}"] = ['values' => ['count' => $delivered * 20]];
        $metrics["http_req_duration{step:{$n},class:document}"] = ['values' => ['p(50)' => $p95 / 2, 'p(95)' => $p95, 'p(99)' => $p95 * 1.2, 'max' => $p95 * 2]];
        $metrics["http_req_failed{step:{$n},class:document}"] = ['values' => ['rate' => $failed]];
    }

    return [
        'status' => 0,
        'started' => $t0,
        'ended' => $t0 + \count($steps) * 30 + 1,
        'summary' => [
            'setup_data' => ['t0' => $t0 * 1000, 'rates' => array_column($steps, 0), 'ramp' => 5, 'step' => 25, 'settle' => 5],
            'metrics' => $metrics,
        ],
    ];
}

describe('reading a ceiling', function () {
    it('takes the last step that held before a failure the next step confirms', function () {
        $result = Analysis::ceiling('document', ceilingRun([
            [100, 100, 40.0, 0.0],
            [110, 110, 60.0, 0.0],
            [121, 121, 300.0, 0.0],
            [133, 120, 900.0, 0.0],
        ]), new Sampler(0, 0));

        expect($result['ceiling'])->toBe(110.0)
            ->and($result['reached'])->toBeTrue()
            ->and($result['first_failed']['target'])->toBe(121.0)
            ->and($result['first_failed']['why'])->toBe(['document p95 300 ms > 250 ms'])
            ->and($result['transients'])->toBe([]);
    });

    it('counts a failed step followed by one that held as a transient, not the ceiling', function () {
        $result = Analysis::ceiling('document', ceilingRun([
            [100, 100, 40.0, 0.0],
            [110, 110, 400.0, 0.0],
            [121, 121, 70.0, 0.0],
            [133, 133, 80.0, 0.02],
            [146, 140, 600.0, 0.0],
        ]), new Sampler(0, 0));

        expect($result['ceiling'])->toBe(121.0)
            ->and($result['transients'])->toHaveCount(1)
            ->and($result['transients'][0]['target'])->toBe(110.0)
            ->and($result['first_failed']['why'])->toBe(['2.00% of requests failed']);
    });

    it('fails a step that could not deliver its rate, however fast its answers', function () {
        $result = Analysis::ceiling('document', ceilingRun([
            [100, 100, 40.0, 0.0],
            [110, 90, 40.0, 0.0],
            [121, 90, 40.0, 0.0],
        ]), new Sampler(0, 0));

        expect($result['ceiling'])->toBe(100.0)
            ->and($result['first_failed']['why'])->toBe(['delivered 90 of 110 a second']);
    });

    it('says the ceiling was not reached when every step held', function () {
        $result = Analysis::ceiling('document', ceilingRun([[100, 100, 40.0, 0.0], [110, 110, 45.0, 0.0]]), new Sampler(0, 0));

        expect($result['ceiling'])->toBe(110.0)
            ->and($result['reached'])->toBeFalse();
    });

    it('reads a split out of a k6 summary, and a missing one as nothing', function () {
        $summary = new Summary(ceilingRun([[100, 100, 40.0, 0.005]])['summary']);

        expect($summary->split(['step' => 1, 'class' => 'document']))->toBe([
            'n' => 2000, 'p50' => 20.0, 'p95' => 40.0, 'p99' => 48.0, 'max' => 80.0, 'failed' => 0.005,
        ])->and($summary->split(['step' => 9])['n'])->toBe(0);
    });
});

describe('the projection', function () {
    it('prices each target from the cost per request of the best configuration', function () {
        $results = results(['document' => [800.0, 2.0, 8.0]]);
        $results['runs'][] = results(['document' => [500.0, 2.0, 8.0]], renderers: 1)['runs'][0];

        $rows = Scaling::projection($results['runs'], 10)['rows'];

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['renderers'])->toBe(4)
            ->and($rows[0]['requests_per_s_per_server_core'])->toBe(100.0)
            ->and($rows[0]['server_cores'])->toBe(500.0)
            ->and($rows[0]['renderer_cores'])->toBe(400.0)
            ->and($rows[0]['machines_by_cores'])->toBe(50)
            ->and($rows[0]['machines_by_ceiling'])->toBe(63)
            ->and($rows[1]['target_requests_per_s'])->toBe(100_000)
            ->and($rows[1]['machines_by_cores'])->toBe(100);
    });
});

describe('the options', function () {
    it('defaults to serve\'s workers and renderers', function () {
        $options = Options::parse([], cores: 10);

        expect($options->workers)->toBe([10])
            ->and($options->renderers)->toBe([5])
            ->and($options->scenarios)->toBe(['sessions', 'ceiling'])
            ->and($options->sessions)->toBe(20_000);
    });

    it('takes lists of configurations, and where each class starts', function () {
        $options = Options::parse(['--workers=10,20', '--renderers=1,2,4,6,8,4', '--start=document:300,mix:250', '--only=ceiling'], cores: 10);

        expect($options->workers)->toBe([10, 20])
            ->and($options->renderers)->toBe([1, 2, 4, 6, 8])
            ->and($options->start)->toBe(['document' => 300, 'mix' => 250])
            ->and($options->scenarios)->toBe(['ceiling']);
    });

    it('refuses what it cannot read', function (array $arguments) {
        expect(fn () => Options::parse($arguments, cores: 10))->toThrow(\InvalidArgumentException::class);
    })->with([
        'an unknown option' => [['--check']],
        'a renderer count of none' => [['--renderers=0']],
        'a worker list with a word in it' => [['--workers=10,many']],
        'a start for no class' => [['--start=page:10']],
        'an unknown class' => [['--classes=document,images']],
        'a quiet threshold of nothing' => [['--quiet-below=0']],
    ]);

    it('steps each class up from where it starts, ten percent at a time', function () {
        expect(LoadCommand::rates(100))->toBe([100, 110, 121, 133, 146, 161, 177, 195, 214, 236, 259, 285, 314, 345]);
    });
});

describe('reading ps', function () {
    it('reads CPU time as macOS and procps write it', function () {
        expect(Sampler::seconds('0:30.05'))->toBe(30.05)
            ->and(Sampler::seconds('12:01.50'))->toBe(721.5)
            ->and(Sampler::seconds('01:02:03'))->toBe(3723.0)
            ->and(Sampler::seconds('2-00:00:01'))->toBe(172801.0);
    });

    it('reads the process table, names and arguments with spaces in them included', function () {
        $processes = Sampler::parseProcesses(implode("\n", [
            '  301     1   0:30.05  20480 frankenphp       /tools/frankenphp run --config Caddyfile',
            '  302   301   1:00.00 102400 node             /Application Support/node --permission renderer.js',
            'garbage',
        ]));

        expect($processes[301]['name'])->toBe('frankenphp')
            ->and($processes[302])->toBe([
                'ppid' => 301,
                'cpu' => 60.0,
                'rss' => 102400,
                'name' => 'node',
                'args' => '/Application Support/node --permission renderer.js',
            ])
            ->and($processes)->toHaveCount(2);
    });

    it('reads each process\'s busiest thread out of ps -M', function () {
        $threads = Sampler::busiestThreads(implode("\n", [
            'USER     PID   TT   %CPU STAT PRI     STIME     UTIME COMMAND',
            'carson 64539   ??   98.5 R    31T   0:10.05   1:00.28 node renderer.js',
            '       64539         3.0 S    31T   0:00.00   0:00.04 ',
            'carson 64540 s002    1.0 S    31T   0:00.35   0:00.38 node renderer.js',
            '       64540        41.2 S    31T   0:00.01   0:00.05 ',
        ]));

        expect($threads)->toBe([64539 => 98.5, 64540 => 41.2]);
    });
});
