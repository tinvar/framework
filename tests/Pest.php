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
 * A generated app key for this run, in a temporary directory, so tests that
 * build the example app never write one into the developer's home.
 */
if ((string) getenv('TINVAR_APP_KEY_FILE') === '') {
    $keyDir = sys_get_temp_dir() . '/tinvar-test-key-' . getmypid();
    putenv("TINVAR_APP_KEY_FILE={$keyDir}/app.key");
    register_shutdown_function(static function () use ($keyDir): void {
        @unlink("{$keyDir}/app.key");
        @unlink("{$keyDir}/.app.key.lock");
        @rmdir($keyDir);
    });
}
