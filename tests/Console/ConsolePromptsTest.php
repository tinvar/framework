<?php

declare(strict_types=1);

/**
 * The console with prompts: --no-interaction read for every command, a
 * cancelled prompt an exit of 130 that reports nothing, and queue:retry all
 * confirmed where a person is there -- and run as it always was where
 * nobody is.
 */

namespace Tinvar\Tests\Console\ConsolePrompts;

use Tinvar\Attributes\Command as CommandName;
use Tinvar\Console\Command;
use Tinvar\Console\Console;
use Tinvar\Console\Input;
use Tinvar\Console\Output;
use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\Cancelled;
use Tinvar\Console\Prompts\Key;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\PendingJob;
use Tinvar\Tests\Jobs\Fixtures\Harness;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

#[CommandName('greet', description: 'Asks a name')]
final class Greet extends Command
{
    public function handle(Input $input, Output $output): int
    {
        $output->line('Hello, ' . ($input->argument(0) ?? Prompts::text('Who?', required: true, name: 'who')) . (Prompts::interactive() ? '' : ' (nobody there)'));

        return 0;
    }
}

/** @return array{Console, Output, ArrayQueue, \Closure(): array{list<string>, list<string>}, \Closure(): list<\Throwable>} */
function console(int $failed = 0): array
{
    $h = new Harness();
    $queue = new ArrayQueue();
    for ($i = 1; $i <= $failed; ++$i) {
        $queue->push(new PendingJob("job-{$i}", 'default', 'payload', 0));
        $job = $queue->reserve(['default'], 0, 60);
        \assert($job !== null);
        $queue->fail($job, 'boom', 1);
    }
    $reported = [];
    $out = [];
    $err = [];
    $output = new Output(static function (string $line) use (&$out): void {
        $out[] = $line;
    }, static function (string $line) use (&$err): void {
        $err[] = $line;
    });
    $console = new Console([Greet::class], $h->orm, $h->container->resolver(), $h->scope, queue: $queue, runner: $h->runner, report: static function (\Throwable $e) use (&$reported): void {
        $reported[] = $e;
    });

    return [$console, $output, $queue, static function () use (&$out, &$err): array {
        return [$out, $err];
    }, static function () use (&$reported): array {
        return $reported;
    }];
}

afterEach(function (): void {
    Prompts::restore();
});

it('asks what a command was not given, and takes it from a fake', function (): void {
    [$console, $output, , $read] = console();
    $prompts = Prompts::fake(['who' => 'Ada']);

    expect($console->run('greet', [], $output))->toBe(0)
        ->and($read()[0])->toBe(['Hello, Ada (nobody there)']);
    $prompts->assertAsked('who');
});

it('reads --no-interaction for every command, which then never sees it', function (): void {
    [$console, $output, , $read] = console();
    Prompts::fakeTerminal();

    expect($console->run('greet', ['Grace', '--no-interaction'], $output))->toBe(0)
        ->and($read()[0])->toBe(['Hello, Grace (nobody there)'])
        // Required with nobody there to answer: a usage error, saying what to pass.
        ->and($console->run('greet', [], $output))->toBe(Console::USAGE)
        ->and($read()[1][0])->toContain("'Who?' needs an answer");
});

it('exits 130 at ctrl-c, reporting nothing to the application\'s log', function (): void {
    [$console, $output, , $read, $reported] = console();
    Prompts::fakeTerminal(['Ad', Key::CTRL_C]);

    expect($console->run('greet', [], $output))->toBe(Cancelled::STATUS)
        ->and($read()[1])->toBe(["Cancelled at 'Who?'."])
        ->and($reported())->toBe([]);
});

it('confirms queue:retry all where a person is there, and retries nothing at no', function (): void {
    [$console, $output, $queue, $read] = console(failed: 2);
    $prompts = Prompts::fake(['retry-all' => false]);

    expect($console->run('queue:retry', ['all'], $output))->toBe(Cancelled::STATUS)
        ->and($queue->failed())->toHaveCount(2)
        ->and($read()[1])->toBe(['Nothing retried.']);
    $prompts->assertAsked('Put all 2 failed job(s) back on their queues?');

    Prompts::fake(['retry-all' => true]);
    expect($console->run('queue:retry', ['all'], $output))->toBe(0)
        ->and($queue->failed())->toBe([]);
});

it('retries all without asking given --force, or with nobody there, as scripts always have', function (): void {
    [$console, $output, $queue] = console(failed: 1);
    $prompts = Prompts::fake();
    expect($console->run('queue:retry', ['all', '--force'], $output))->toBe(0)
        ->and($queue->failed())->toBe([]);
    $prompts->assertNothingAsked();

    Prompts::restore();
    [$console, $output, $queue] = console(failed: 1);
    expect($console->run('queue:retry', ['all'], $output))->toBe(0)
        ->and($queue->failed())->toBe([]);
});

it('asks nothing to retry jobs named one by one', function (): void {
    [$console, $output, $queue] = console(failed: 2);
    $prompts = Prompts::fake();

    expect($console->run('queue:retry', ['job-1'], $output))->toBe(0)
        ->and($queue->failed())->toHaveCount(1);
    $prompts->assertNothingAsked();
});
