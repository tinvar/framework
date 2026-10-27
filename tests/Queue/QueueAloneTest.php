<?php

declare(strict_types=1);

/**
 * The queue's storage stands on the database alone (ADR 0077): run in a
 * process of its own with nothing else of Tinvar's loadable, a database
 * queue and an array queue each take, hold, release, fail, retry and
 * finish jobs, and nothing of the framework is asked for.
 */

namespace Tinvar\Tests\Queue\Alone;

it('runs with nothing of the framework but itself, the database and support', function () {
    $scratch = sys_get_temp_dir() . '/tinvar-queue-alone-' . bin2hex(random_bytes(4));
    mkdir($scratch, 0o700);

    try {
        exec(
            escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/Fixtures/alone.php') . ' ' . escapeshellarg(\dirname(__DIR__, 4)) . ' ' . escapeshellarg($scratch) . ' 2>&1',
            $output,
            $status,
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($scratch));
    }

    expect($status)->toBe(0, implode("\n", $output))
        ->and($output)->not->toBeEmpty()
        ->and(array_filter($output, static fn (string $line): bool => !str_starts_with($line, 'ok: ')))->toBe([])
        ->and($output)->toContain('ok: nothing of the framework was loaded');
});
