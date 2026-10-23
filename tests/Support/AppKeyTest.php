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
    mkdir($this->dir, 0700);
    file_put_contents($this->file, str_repeat('w', 44) . "\n");
    chmod($this->file, 0600);

    expect(AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toBe(str_repeat('w', 44));
});

// Created private, not tightened afterwards: under a permissive umask the
// key was readable by other users between the write and the chmod.
it('creates the key file private even under a permissive umask', function () {
    $previous = umask(0000);
    try {
        AppKey::resolve('TINVAR_TEST_KEY', $this->file);
    } finally {
        umask($previous);
    }

    expect(fileperms($this->file) & 0777)->toBe(0600);
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
        mkdir($this->dir, 0700);
        file_put_contents($this->file, "short\n");
        chmod($this->file, 0600);
    }

    expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))
        ->toThrow(InvalidArgumentException::class, 'at least 32 bytes');
})->with(['env', 'file']);

// Whoever can write the directory can plant a key they know; a readable
// file is a leaked key; a symlink can point anywhere.
describe('a key file it cannot trust', function () {
    it('refuses a file others can read', function () {
        mkdir($this->dir, 0700);
        file_put_contents($this->file, str_repeat('w', 44));
        chmod($this->file, 0644);

        expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toThrow(RuntimeException::class, 'mode 0600');
    });

    it('refuses a directory others can write', function () {
        mkdir($this->dir);
        chmod($this->dir, 0777);

        expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toThrow(RuntimeException::class, 'writable by no one else');
    });

    it('refuses a symlink in place of the file', function () {
        mkdir($this->dir, 0700);
        $target = $this->dir . '/elsewhere';
        file_put_contents($target, str_repeat('w', 44));
        chmod($target, 0600);
        symlink($target, $this->file);

        expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toThrow(RuntimeException::class, 'regular file');
        unlink($this->file);
        unlink($target);
    });
});
