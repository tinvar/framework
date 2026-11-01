<?php

declare(strict_types=1);

/**
 * Forms a person can step back through, spinners while work runs, and
 * progress bars over it, on a fake terminal.
 */

use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\Cancelled;
use Tinvar\Console\Prompts\Key;
use Tinvar\Console\Prompts\Progress;

afterEach(function (): void {
    Prompts::restore();
});

it('asks a form\'s steps in turn, handing each the answers so far, keyed by name or place', function (): void {
    Prompts::fakeTerminal(['Rack', Key::ENTER, Key::ENTER, Key::ENTER]);

    $answers = Prompts::form()
        ->text('What is the model called?', required: true, name: 'name')
        ->add(static fn (array $answers): string => Prompts::text('Table', default: strtolower($answers['name']) . 's'), name: 'table')
        ->confirm('Make a factory?', default: false)
        ->submit();

    expect($answers)->toBe(['name' => 'Rack', 'table' => 'racks', 2 => false]);
});

it('goes back a step at shift-tab, with its answer as it was left, and forward again', function (): void {
    $terminal = Prompts::fakeTerminal(['Rack', Key::ENTER, Key::SHIFT_TAB, Key::BACKSPACE, 'm', Key::ENTER, 'y', Key::ENTER]);

    $answers = Prompts::form()
        ->text('Name', name: 'name')
        ->confirm('Factory?', default: false, name: 'factory')
        ->submit();

    expect($answers)->toBe(['name' => 'Racm', 'factory' => true])
        ->and(implode("\n", $terminal->frames()))->toContain('shift+tab back')
        // The step gone back from is taken off the screen.
        ->and(substr_count($terminal->screen(), 'Factory?'))->toBe(1)
        ->and(substr_count($terminal->screen(), 'Name'))->toBe(1);
});

it('goes back at ctrl-u only with nothing to clear, and never from the first step', function (): void {
    Prompts::fakeTerminal([Key::SHIFT_TAB, 'a', Key::ENTER, 'b', Key::CTRL_U, Key::CTRL_U, Key::BACKSPACE, 'c', Key::ENTER, 'd', Key::ENTER]);

    expect(Prompts::form()->text('One')->text('Two')->submit())->toBe([0 => 'c', 1 => 'd']);
});

it('passes over a step that asked nothing on the way back', function (): void {
    Prompts::fakeTerminal(['x', Key::ENTER, Key::SHIFT_TAB, Key::ENTER, Key::ENTER]);

    $answers = Prompts::form()
        ->text('First', name: 'first')
        ->add(static fn (): string => 'computed', name: 'quiet')
        ->confirm('Last?', name: 'last')
        ->submit();

    expect($answers)->toBe(['first' => 'x', 'quiet' => 'computed', 'last' => true]);
});

it('cancels the whole form at ctrl-c', function (): void {
    Prompts::fakeTerminal(['x', Key::ENTER, Key::CTRL_C]);

    expect(fn () => Prompts::form()->text('One')->text('Two')->submit())->toThrow(Cancelled::class);
});

it('answers a form from a fake by name, and with defaults where nobody is there', function (): void {
    $fake = Prompts::fake(['name' => 'Rack', 'kind' => 'model']);
    expect(Prompts::form()->text('Name', name: 'name')->select('Kind', ['page' => 'Page', 'model' => 'Model'], name: 'kind')->submit())
        ->toBe(['name' => 'Rack', 'kind' => 'model']);
    $fake->assertAllAnswered();

    Prompts::restore();
    Prompts::fakeTerminal();
    Prompts::withoutInteraction();
    expect(Prompts::form()->text('Name', default: 'x')->confirm('Go?')->submit())->toBe(['x', true]);
});

it('spins beside a message while the work runs, then takes it off, and returns what the work returned', function (): void {
    $terminal = Prompts::fakeTerminal();
    $during = '';

    $answer = Prompts::spin(static function () use ($terminal, &$during): int {
        $during = $terminal->screen();

        return 42;
    }, 'Building the app');

    expect($answer)->toBe(42)
        ->and($during)->toBe(' ⠋ Building the app')
        ->and($terminal->screen())->toBe('')
        ->and(str_ends_with($terminal->output(), Prompts\Screen::SHOW_CURSOR))->toBeTrue();
});

it('takes the spinner off when the work throws', function (): void {
    $terminal = Prompts::fakeTerminal();

    expect(fn () => Prompts::spin(static fn (): never => throw new RuntimeException('failed'), 'Working'))->toThrow(RuntimeException::class, 'failed')
        ->and($terminal->screen())->toBe('');
});

// spin() showed the cursor again in a finally, which a signal, exit() or a
// fatal error never reaches: ctrl-c during a spinner left the shell with no
// cursor.
it('shows the cursor again and stops the spinner when a signal, exit() or a fatal error ends the work', function (string $how, int $code): void {
    if (!\function_exists('pcntl_signal') || !\function_exists('posix_kill')) {
        $this->markTestSkipped('pcntl and posix are not loaded.');
    }
    $process = proc_open([\PHP_BINARY, __DIR__ . '/Fixtures/spin-interrupted.php', $how], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    \assert(\is_resource($process));
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    expect($out)->toStartWith(Prompts\Screen::HIDE_CURSOR)
        ->and(str_ends_with($out, "\r\e[K" . Prompts\Screen::SHOW_CURSOR))->toBeTrue()
        ->and(substr_count($out, Prompts\Screen::SHOW_CURSOR))->toBe(1)
        ->and($out)->not->toContain('returned')
        ->and($exit)->toBe($code);
})->with([
    'ctrl-c' => ['sigint', 130],
    'SIGTERM' => ['sigterm', 143],
    'exit()' => ['exit', 3],
    'a fatal error' => ['fatal', 255],
]);

it('puts back the signal handlers spin() replaced once the work is done', function (): void {
    if (!\function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl is not loaded.');
    }
    Prompts::fakeTerminal();
    $mine = static function (): void {};
    pcntl_signal(SIGINT, $mine);
    $before = [SIGINT => pcntl_signal_get_handler(SIGINT), SIGTERM => pcntl_signal_get_handler(SIGTERM)];

    try {
        $during = Prompts::spin(static fn (): array => [SIGINT => pcntl_signal_get_handler(SIGINT), SIGTERM => pcntl_signal_get_handler(SIGTERM)], 'Working');

        expect($during[SIGINT])->toBeInstanceOf(Closure::class)->not->toBe($mine)
            ->and($during[SIGTERM])->toBeInstanceOf(Closure::class)
            ->and([SIGINT => pcntl_signal_get_handler(SIGINT), SIGTERM => pcntl_signal_get_handler(SIGTERM)])->toBe($before);
    } finally {
        pcntl_signal(SIGINT, SIG_DFL);
    }
});

it('turns a real spinner from a forked process, and stops it with the work', function (): void {
    if (!\function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not loaded.');
    }
    $file = tempnam(sys_get_temp_dir(), 'spin');
    $out = fopen($file, 'w+');
    \assert(\is_resource($out));
    $terminal = new class (STDIN, $out) implements Prompts\Terminal {
        /** @param resource $in @param resource $out */
        public function __construct(private $in, private $out) {}

        public function interactive(): bool
        {
            return true;
        }

        public function colors(): bool
        {
            return false;
        }

        public function animates(): bool
        {
            return true;
        }

        public function columns(): int
        {
            return 80;
        }

        public function lines(): int
        {
            return 24;
        }

        public function read(): string
        {
            return '';
        }

        public function write(string $text): void
        {
            fwrite($this->out, $text);
        }

        public function raw(): void {}

        public function restore(): void {}
    };
    Prompts::useTerminal($terminal);

    // The work lasts until the spinner has turned three times, however long
    // a loaded machine takes to schedule the forked process: waiting a fixed
    // while instead counts frames the scheduler may not have run.
    Prompts::spin(static function () use ($file): void {
        $deadline = microtime(true) + 30;
        while (substr_count((string) file_get_contents($file), 'Waiting') < 4 && microtime(true) < $deadline) {
            usleep(10_000);
        }
    }, 'Waiting');
    $drawn = (string) file_get_contents($file);
    usleep(200_000);

    expect(substr_count($drawn, 'Waiting'))->toBeGreaterThan(2)
        ->and($drawn)->toContain('⠙')
        // Killed with the work: nothing drawn after it was taken off.
        ->and((string) file_get_contents($file))->toBe($drawn)
        ->and(str_ends_with($drawn, "\r\e[K" . Prompts\Screen::SHOW_CURSOR))->toBeTrue();
    unlink($file);
});

it('draws a progress bar advanced by hand, and leaves it finished', function (): void {
    $terminal = Prompts::fakeTerminal(columns: 40);

    $progress = Prompts::progress('Seeding', 4, hint: 'customers');
    expect($progress)->toBeInstanceOf(Progress::class);
    $progress->start();
    $progress->advance();
    expect($terminal->screen())->toContain('1/4')->toContain('████████░░░░░░░░░░░░░░░░░░░░░░░░░░')->toContain('customers');
    $progress->advance(2)->label('Seeding orders');
    $progress->finish();

    expect($terminal->screen())->toBe(implode("\n", [
        ' ┌ Seeding orders ─────────────── 4/4 ┐',
        ' │ ██████████████████████████████████ │',
        ' └────────────────────────────────────┘',
        '   customers',
    ]))->and($progress->done())->toBe(4);
});

it('goes over what it is given with a callback, keeping keys, and prints above the bar', function (): void {
    $terminal = Prompts::fakeTerminal();

    $results = Prompts::progress('Importing', ['a' => 1, 'b' => 2], static function (int $n, Progress $progress): int {
        $progress->above(static fn () => Prompts::note("row {$n}"));

        return $n * 10;
    });

    expect($results)->toBe(['a' => 10, 'b' => 20])
        ->and(explode("\n", $terminal->screen()))->toHaveCount(5)
        ->and($terminal->screen())->toStartWith(" row 1\n row 2\n ┌ Importing")->toContain('2/2');
});

it('counts where it has no total, and draws nothing where nobody watches', function (): void {
    $terminal = Prompts::fakeTerminal();
    $generator = (static function (): Generator {
        yield 1;
        yield 2;
    })();
    Prompts::progress('Reading', $generator, static fn (int $n): int => $n);
    expect($terminal->screen())->toContain('2 done');

    $terminal = Prompts::fakeTerminal();
    Prompts::withoutInteraction();
    Prompts::progress('Quiet', [1, 2], static fn (int $n): int => $n);
    expect($terminal->output())->toBe('');
});

it('throttles redraws to when the percentage changes', function (): void {
    $terminal = Prompts::fakeTerminal();

    Prompts::progress('Many', range(1, 5000), static fn (int $n): int => $n);

    expect(\count($terminal->frames()))->toBeLessThan(150);
});
