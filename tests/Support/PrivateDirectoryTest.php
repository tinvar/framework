<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\PrivateDirectory;

use Tinvar\Support\PrivateDirectory;

// The renderer's socket goes in one of these. Under the project tree, a
// checkout a few directories deep already passed macOS's 104-byte cap.
it('makes a short directory only this user can enter', function () {
    $directory = PrivateDirectory::create('test');

    try {
        $stat = stat($directory);

        expect(is_dir($directory))->toBeTrue()
            ->and($stat['mode'] & 0777)->toBe(0700)
            ->and($stat['uid'])->toBe(posix_geteuid())
            ->and(\strlen("{$directory}/renderer.sock"))->toBeLessThan(PrivateDirectory::MAX_SOCKET_PATH)
            ->and(basename($directory))->toMatch('/^test-[0-9a-f]{10}$/');
    } finally {
        PrivateDirectory::remove($directory);
    }
});

it('makes a new directory every time', function () {
    $a = PrivateDirectory::create('test');
    $b = PrivateDirectory::create('test');

    try {
        expect($a)->not->toBe($b);
    } finally {
        PrivateDirectory::remove($a);
        PrivateDirectory::remove($b);
    }
});

// Exactly 0700 whatever the umask, which can only narrow mkdir()'s mode.
it('is 0700 under a permissive umask too', function () {
    $previous = umask(0);

    try {
        $directory = PrivateDirectory::create('test');
    } finally {
        umask($previous);
    }

    try {
        expect(stat($directory)['mode'] & 0777)->toBe(0700);
    } finally {
        PrivateDirectory::remove($directory);
    }
});

it('removes the directory and the files in it', function () {
    $directory = PrivateDirectory::create('test');
    file_put_contents("{$directory}/renderer.log", 'log');
    touch("{$directory}/renderer.sock");

    PrivateDirectory::remove($directory);

    expect(file_exists($directory))->toBeFalse();
});

// Not recursive: a path that turns out to hold a directory is not one this
// class made, and is not emptied blindly.
it('leaves a directory with subdirectories in place', function () {
    $directory = PrivateDirectory::create('test');
    mkdir("{$directory}/nested");

    try {
        PrivateDirectory::remove($directory);

        expect(is_dir("{$directory}/nested"))->toBeTrue();
    } finally {
        rmdir("{$directory}/nested");
        rmdir($directory);
    }
});

it('ignores a path that is not a directory', function () {
    PrivateDirectory::remove('/tmp/tinvar-no-such-directory-' . bin2hex(random_bytes(4)));

    expect(true)->toBeTrue();
});

it('refuses a prefix that is not a short plain name', function (string $prefix) {
    expect(fn () => PrivateDirectory::create($prefix))->toThrow(\InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'a separator' => ['../escape'],
    'too long' => [str_repeat('a', 17)],
    'upper case' => ['Tinvar'],
]);

it('gives up when the base directory cannot hold one', function () {
    expect(fn () => PrivateDirectory::create('test', '/nonexistent-' . bin2hex(random_bytes(4))))
        ->toThrow(\RuntimeException::class, 'Could not create a private directory');
});
