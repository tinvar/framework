<?php

declare(strict_types=1);

/**
 * Runs `tinvar serve --dev` against the fake console, watching DEV_TEST_WATCH, for
 * DevCommandTest -- in a process of its own, since it runs until stopped.
 *
 *   php dev-runner.php [arguments for tinvar serve --dev...]
 */

require \dirname(__DIR__, 5) . '/vendor/autoload.php';

$console = __DIR__ . '/dev-console.php';

exit(new Tinvar\Server\DevCommand(
    console: $console,
    watch: [(string) getenv('DEV_TEST_WATCH')],
    codegen: static fn (string $app): array => [PHP_BINARY, $console, 'codegen', "--out={$app}/resources/types"],
    poll: 0.1,
)->run(array_slice($_SERVER['argv'] ?? [], 1)));
