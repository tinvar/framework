<?php

declare(strict_types=1);

/**
 * A console for DevCommandTest: `serve` and `codegen` as the real CLI's are
 * run, saying what they were given into files named after DEV_TEST_LOG.
 *
 *   serve    writes its arguments and what it can see of the toolbar's run,
 *            prints the line a caller waits for, and runs until SIGTERM --
 *            or exits 3 once DEV_TEST_LOG.crash appears
 *   codegen  appends a line to DEV_TEST_LOG.codegen and says what it wrote,
 *            or fails while DEV_TEST_LOG.fail exists
 */

$log = (string) getenv('DEV_TEST_LOG');
$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$command = array_shift($arguments);

if ($command === 'codegen') {
    if (is_file("{$log}.fail")) {
        fwrite(STDERR, "PHP Parse error: syntax error, unexpected '}' in Product.php on line 9\n");
        exit(255);
    }
    file_put_contents("{$log}.codegen", implode(' ', $arguments) . "\n", FILE_APPEND);
    echo "wrote /types/generated.ts (120 bytes)\nunchanged /types/modules.d.ts (40 bytes)\n";
    exit(0);
}

if ($command === 'serve') {
    $run = (string) getenv('TINVAR_DEV_TOOLBAR');
    file_put_contents("{$log}.serve", json_encode([
        'arguments' => $arguments,
        'run' => $run,
        'key' => is_file("{$run}/toolbar.key") ? strlen((string) file_get_contents("{$run}/toolbar.key")) : null,
        'mode' => is_dir($run) ? fileperms($run) & 0777 : null,
        'pid' => getmypid(),
    ]));

    $stopping = false;
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stopping): void {
        $stopping = true;
    });

    echo "Listening on http://127.0.0.1:65000\n";
    while (!$stopping) {
        if (is_file("{$log}.crash")) {
            exit(3);
        }
        usleep(50_000);
    }
    file_put_contents("{$log}.stopped", '1');
    exit(0);
}

fwrite(STDERR, "dev-console: unknown command {$command}\n");
exit(64);
