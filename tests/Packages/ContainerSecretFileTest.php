<?php

declare(strict_types=1);

// resources/container/secret-file: what the entrypoint, as root, will read a
// compose secret from, and which variable it sets (ADR 0059). Run against a
// directory of the test's own standing in for /run/secrets.

function secretFile(string $dir, string $variable, string $path): array
{
    $script = dirname(__DIR__, 2) . '/resources/container/secret-file';
    $process = proc_open(['bash', $script, $dir, $variable, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    \assert(\is_resource($process));
    $out = trim((string) stream_get_contents($pipes[1]));
    $err = trim((string) stream_get_contents($pipes[2]));

    return [proc_close($process), $out, $err];
}

beforeEach(function () {
    $root = (string) realpath(sys_get_temp_dir()) . '/tinvar-secret-file-' . bin2hex(random_bytes(4));
    mkdir("{$root}/run/secrets", 0o700, true);
    file_put_contents("{$root}/run/secrets/app_key", 'k');
    file_put_contents("{$root}/shadow", 'not a secret');
    $this->root = $root;
    $this->dir = "{$root}/run/secrets";
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->root));
});

it('names the variable a TINVAR_ secret in the directory sets', function () {
    expect(secretFile($this->dir, 'TINVAR_APP_KEY_FILE', "{$this->dir}/app_key"))->toBe([0, 'TINVAR_APP_KEY', ''])
        ->and(secretFile($this->dir, 'APP_DATABASE_PASSWORD_FILE', "{$this->dir}/app_key")[1])->toBe('APP_DATABASE_PASSWORD');
});

it('refuses a variable that would set what a process loads or runs, or any other outside TINVAR_', function (string $variable) {
    [$status, $out, $err] = secretFile($this->dir, $variable, "{$this->dir}/app_key");

    expect($status)->toBe(78)->and($out)->toBe('')->and($err)->toContain('only a TINVAR_*_FILE variable');
})->with(['LD_PRELOAD_FILE', 'PATH_FILE', 'BASH_ENV_FILE', 'APP_ENV_FILE', 'TINVAR__FILE']);

it('refuses a path out of the directory, or not directly in it', function (string $path) {
    [$status, $out] = secretFile($this->dir, 'TINVAR_APP_KEY_FILE', str_replace('DIR', $this->dir, $path));

    expect($status)->toBe(78)->and($out)->toBe('');
})->with([
    'a .. component' => ['DIR/../../shadow'],
    'a .. after a name' => ['DIR/app_key/../app_key'],
    'an empty component' => ['DIR//app_key'],
    'a . component' => ['DIR/./app_key'],
    'a further directory' => ['DIR/sub/app_key'],
    'the directory itself' => ['DIR/'],
    'another directory' => ['/etc/passwd'],
    'a name that is not there' => ['DIR/missing'],
]);

it('refuses a symlink, as the file or on the way to it', function () {
    symlink("{$this->root}/shadow", "{$this->dir}/link");
    expect(secretFile($this->dir, 'TINVAR_APP_KEY_FILE', "{$this->dir}/link")[0])->toBe(78);

    symlink("{$this->root}/run", "{$this->root}/run-link");
    [$status, , $err] = secretFile("{$this->root}/run-link/secrets", 'TINVAR_APP_KEY_FILE', "{$this->root}/run-link/secrets/app_key");
    expect($status)->toBe(78)->and($err)->toContain('is a symlink');
});
