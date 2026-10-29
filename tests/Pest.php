<?php

declare(strict_types=1);

/*
 * What the framework's suite needs before it runs, and the testing layer's
 * and codegen's, which load this too: the workspace's tests/Pest.php when
 * every package's suite runs at once, and each package's own when its suite
 * runs alone (bin/package-alone).
 */

// The testing layer's Pest-style functions: get(), actingAs(), query() ...
\Tinvar\Testing\Testing::functions();

/*
 * A generated app key and probe token for this run, in a temporary
 * directory, so tests that build the example app never write either into
 * the developer's home.
 */
foreach (['TINVAR_APP_KEY_FILE' => 'app.key', 'TINVAR_PROBE_TOKEN_FILE' => 'probe-token'] as $variable => $name) {
    if ((string) getenv($variable) !== '') {
        continue;
    }
    $keyDir = sys_get_temp_dir() . '/tinvar-test-key-' . getmypid();
    putenv("{$variable}={$keyDir}/{$name}");
    register_shutdown_function(static function () use ($keyDir, $name): void {
        @unlink("{$keyDir}/{$name}");
        @unlink("{$keyDir}/.{$name}.lock");
        @rmdir($keyDir);
    });
}
