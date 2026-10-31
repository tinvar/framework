<?php

declare(strict_types=1);

/*
 * What the framework's suite needs before it runs, and the testing layer's
 * and codegen's, which load this too: the workspace's tests/Pest.php when
 * every package's suite runs at once, and each package's own when its suite
 * runs alone (bin/package-alone).
 */

// A module's own run (bin/module-alone, ADR 0114) holds the process to what
// that module may load, before anything else is; the testing layer stands
// over the kernel, so it has none of its functions. Every other run has the
// testing layer's Pest-style functions: get(), actingAs(), query() ...
$alone = getenv('TINVAR_MODULE_ALONE');
if (\is_string($alone) && $alone !== '') {
    require_once __DIR__ . '/Support/Fixtures/module-alone.php';
    \Tinvar\Tests\Support\Fixtures\ModuleAlone::hold($alone);
} else {
    \Tinvar\Testing\Testing::functions();
}

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

/*
 * APCu, where the CLI has it (`apc.enable_cli=1`, as CI's PHP does), is one
 * store for the whole worker process: a throttle count, a cached entry or a
 * metric one test put would reach every test after it in that worker -- the
 * shop's throttles, counting every test's requests from 127.0.0.1, refused
 * the later ones with a 429. Each test starts with it empty, as a fresh
 * server does. Every package's tests, since the workspace's run loads this
 * file once for all of them.
 */
if (\function_exists('apcu_enabled') && apcu_enabled()) {
    pest()->beforeEach(function (): void {
        apcu_clear_cache();
    })->in(\dirname(__DIR__, 2));
}
