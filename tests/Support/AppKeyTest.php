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

    $leftovers = array_filter(glob($this->dir . '/.*.key.*') ?: [], static fn (string $f): bool => !str_ends_with($f, '.lock'));
    expect($leftovers)->toBe([]);
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

        expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toThrow(RuntimeException::class, 'readable by no one outside');
    });

    it('refuses a directory others can write', function () {
        mkdir($this->dir);
        chmod($this->dir, 0777);

        expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toThrow(RuntimeException::class, 'writable by no one else');
    });

    it('refuses a symlink to a file others can read', function () {
        mkdir($this->dir, 0700);
        $target = $this->dir . '/elsewhere';
        file_put_contents($target, str_repeat('w', 44));
        chmod($target, 0644);
        symlink($target, $this->file);

        expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toThrow(RuntimeException::class, 'readable by no one outside');
        unlink($this->file);
        unlink($target);
    });

    it('refuses a symlink to nothing', function () {
        mkdir($this->dir, 0700);
        symlink($this->dir . '/missing', $this->file);

        expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toThrow(RuntimeException::class, 'symlink to nothing');
        unlink($this->file);
    });
});

// The same key text in either place is the same key: moving it from the
// file to the environment must not change what signs.
it('reads a key the same from the environment and from the file', function () {
    $key = base64_encode(random_bytes(32));
    putenv("TINVAR_TEST_KEY=\u{FEFF}{$key}\r\n");
    $fromEnv = AppKey::resolve('TINVAR_TEST_KEY', $this->file);
    putenv('TINVAR_TEST_KEY');

    mkdir($this->dir, 0700);
    file_put_contents($this->file, "\u{FEFF}{$key}\n");
    chmod($this->file, 0600);

    expect($fromEnv)->toBe($key)
        ->and(AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toBe($key);
});

it('names an unreadable key file rather than warning and generating', function () {
    mkdir($this->dir, 0700);
    file_put_contents($this->file, str_repeat('w', 44));
    chmod($this->file, 0000);

    try {
        expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toThrow(RuntimeException::class);
    } finally {
        chmod($this->file, 0600);
    }
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root reads anything');

it('clears temporary key files a crash left behind', function () {
    mkdir($this->dir, 0700);
    $stale = $this->dir . '/.app.key.deadbeef';
    file_put_contents($stale, 'old');
    touch($stale, time() - 3600);

    AppKey::resolve('TINVAR_TEST_KEY', $this->file);

    expect(file_exists($stale))->toBeFalse();
});

// Several processes starting at once must all end up with one key.
it('gives concurrent first starts one key between them', function () {
    $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
    $script = sprintf(
        'require %s; echo Tinvar\\Support\\AppKey::resolve("TINVAR_UNSET_KEY", %s);',
        var_export($autoload, true),
        var_export($this->file, true),
    );

    $processes = [];
    for ($i = 0; $i < 8; $i++) {
        $processes[] = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outputs[$i] = $pipes;
    }
    $keys = [];
    foreach ($processes as $i => $process) {
        $keys[] = stream_get_contents($outputs[$i][1]);
        proc_close($process);
    }

    expect(array_unique($keys))->toHaveCount(1)
        ->and($keys[0])->toBe(trim((string) file_get_contents($this->file)));
});

// Real setups the first version refused: a dotfiles-managed state directory
// is a symlink; a Kubernetes secret is a symlink to the mounted file; a key
// shared with the web server is group-readable.
describe('a key file set up by someone sensible', function () {
    it('accepts a symlinked directory, judged by what it resolves to', function () {
        $real = $this->dir . '-real';
        mkdir($real, 0700);
        mkdir($this->dir, 0700);
        rmdir($this->dir);
        symlink($real, $this->dir);

        $key = AppKey::resolve('TINVAR_TEST_KEY', $this->file);

        expect(trim(file_get_contents($real . '/app.key')))->toBe($key);
        unlink($real . '/app.key');
        @unlink($real . '/.app.key.lock');
        unlink($this->dir);
        rmdir($real);
    });

    it('accepts a key file that is a symlink to a private file', function () {
        mkdir($this->dir, 0700);
        $target = $this->dir . '/mounted';
        file_put_contents($target, str_repeat('m', 44));
        chmod($target, 0600);
        symlink($target, $this->file);

        expect(AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toBe(str_repeat('m', 44));
        unlink($this->file);
        unlink($target);
    });

    it('accepts a key file readable by its group', function () {
        mkdir($this->dir, 0700);
        file_put_contents($this->file, str_repeat('g', 44));
        chmod($this->file, 0640);

        expect(AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toBe(str_repeat('g', 44));
    });
});

it('refuses a key path that names a directory', function (string $suffix) {
    mkdir($this->dir, 0700);

    expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->dir . $suffix))
        ->toThrow(InvalidArgumentException::class, 'not a directory');
})->with(['', '/']);

// A UTF-16 file or a NUL passed the length check and silently became a
// different key.
it('refuses a key that is not printable ASCII', function (string $key) {
    putenv('TINVAR_TEST_KEY=' . $key);

    expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))
        ->toThrow(InvalidArgumentException::class, 'printable ASCII');
})->with([
    'accented' => [str_repeat('é', 20)],
    'inner space' => [str_repeat('k', 20) . ' ' . str_repeat('k', 20)],
]);

it('strips a byte-order mark behind leading whitespace too', function () {
    $key = base64_encode(random_bytes(32));
    putenv("TINVAR_TEST_KEY= \u{FEFF}{$key}");

    expect(AppKey::resolve('TINVAR_TEST_KEY', $this->file))->toBe($key);
});

// The environment cannot carry a NUL, so the UTF-16 case is a file.
it('refuses a UTF-16 key file', function () {
    mkdir($this->dir, 0700);
    file_put_contents($this->file, mb_convert_encoding(str_repeat('k', 40), 'UTF-16LE'));
    chmod($this->file, 0600);

    expect(fn () => AppKey::resolve('TINVAR_TEST_KEY', $this->file))
        ->toThrow(InvalidArgumentException::class, 'printable ASCII');
});
