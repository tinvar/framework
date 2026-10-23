<?php

declare(strict_types=1);

use Tinvar\Support\AppKey;

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/tinvar-appkey-' . bin2hex(random_bytes(4));
    $this->file = $this->dir . '/app.key';
    putenv('TINVAR_TEST_KEY');
});

afterEach(function () {
    putenv('TINVAR_TEST_KEY');
    foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
    if (is_dir($this->dir)) {
        rmdir($this->dir);
    }
});

it('generates a key into the file when none is set', function () {
    $key = AppKey::resolve('TINVAR_TEST_KEY', $this->file);

    expect(strlen(base64_decode($key, true)))->toBe(32)
        ->and(trim(file_get_contents($this->file)))->toBe($key)
        ->and(fileperms($this->file) & 0777)->toBe(0600);
});

// Under php -S every request is a new process. A key made up per process
// would sign tokens no other process could verify.
it('reads the same key back on every later resolve', function () {
    $first = AppKey::resolve('TINVAR_TEST_KEY', $this->file);

    expect(AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toBe($first);
});

it('prefers the environment, and leaves the file alone', function () {
    putenv('TINVAR_TEST_KEY=' . str_repeat('e', 40));

    expect(AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toBe(str_repeat('e', 40))
        ->and(file_exists($this->file))->toBeFalse();
});

// Two first requests at once: one key wins, and the other process reads it.
it('takes the key already in place when another process got there first', function () {
    mkdir($this->dir);
    file_put_contents($this->file, str_repeat('w', 44) . "\n");

    expect(AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toBe(str_repeat('w', 44));
});

it('leaves no temporary file behind', function () {
    AppKey::resolve('TINVAR_TEST_KEY', $this->file);

    expect(glob($this->dir . '/.*.key.*') ?: [])->toBe([]);
});

// Never replaced silently: a new key would invalidate every token in flight.
it('refuses a short key from either source rather than replacing it', function (string $source) {
    if ($source === 'env') {
        putenv('TINVAR_TEST_KEY=short');
    } else {
        mkdir($this->dir);
        file_put_contents($this->file, "short\n");
    }

    expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))
        ->toThrow(InvalidArgumentException::class, 'at least 32 bytes');
})->with(['env', 'file']);
