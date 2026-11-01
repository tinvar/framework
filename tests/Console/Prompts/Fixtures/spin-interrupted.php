<?php

declare(strict_types=1);

/**
 * A spinner whose work never returns into spin(): a signal, exit() or a
 * fatal error ends the process inside it. What it draws goes to standard
 * output for the test to read. The argument says how it ends.
 */

require \dirname(__DIR__, 6) . '/vendor/autoload.php';

use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\Terminal;

Prompts::useTerminal(new class implements Terminal {
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
        return \function_exists('pcntl_fork') && \function_exists('posix_kill');
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
        fwrite(STDOUT, $text);
    }

    public function raw(): void {}

    public function restore(): void {}
});

$how = $argv[1] ?? 'sigint';
Prompts::spin(static function () use ($how): void {
    match ($how) {
        'sigint' => posix_kill((int) getmypid(), SIGINT),
        'sigterm' => posix_kill((int) getmypid(), SIGTERM),
        'exit' => exit(3),
        'fatal' => (static function (): void {
            ini_set('memory_limit', '8M');
            $hog = str_repeat('x', 64 * 1024 * 1024);
            echo \strlen($hog);
        })(),
        default => null,
    };
    // A signal is taken between statements: the work goes on until it is.
    for ($i = 0; $i < 500; ++$i) {
        usleep(10_000);
    }
}, 'Working');
fwrite(STDOUT, 'returned');
