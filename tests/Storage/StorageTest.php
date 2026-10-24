<?php

declare(strict_types=1);

/**
 * The two stores, held to one contract: keys only of the shape the
 * framework makes, whole writes, and nothing outside the root.
 */

use Tinvar\Storage\LocalStorage;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Storage\Storage;
use Tinvar\Storage\StorageException;
use Tinvar\Storage\StorageKey;

/** @return resource */
function storageStreamOf(string $contents)
{
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

function storageTestRoot(): string
{
    return sys_get_temp_dir() . '/tinvar-storage-' . bin2hex(random_bytes(6));
}

function removeStorageTestRoot(string $root): void
{
    exec('rm -rf ' . escapeshellarg($root));
}

dataset('stores', [
    'memory' => [fn (): array => [new MemoryStorage(), null]],
    'local' => [function (): array {
        $root = storageTestRoot();

        return [new LocalStorage($root), $root];
    }],
]);

describe('every store', function () {
    it('writes, reads, sizes and deletes by key', function (Closure $make) {
        /** @var Storage $storage */
        [$storage, $root] = $make();
        $key = StorageKey::generate('receipts/2026', 'pdf');

        try {
            expect($storage->exists($key))->toBeFalse()
                ->and($storage->read($key))->toBeNull()
                ->and($storage->size($key))->toBeNull();

            $storage->put($key, storageStreamOf('first'));
            $storage->put($key, storageStreamOf('second, longer'));
            $read = $storage->read($key);

            expect(stream_get_contents($read))->toBe('second, longer')
                ->and($storage->size($key))->toBe(14)
                ->and($storage->exists($key))->toBeTrue();
            fclose($read);

            $storage->delete($key);
            $storage->delete($key);
            expect($storage->exists($key))->toBeFalse();
        } finally {
            if ($root !== null) {
                removeStorageTestRoot($root);
            }
        }
    })->with('stores');

    it('refuses every key the framework would not make', function (Closure $make, string $key) {
        [$storage, $root] = $make();

        try {
            expect(fn () => $storage->put($key, storageStreamOf('x')))->toThrow(StorageException::class)
                ->and(fn () => $storage->read($key))->toThrow(StorageException::class)
                ->and(fn () => $storage->delete($key))->toThrow(StorageException::class);
        } finally {
            if ($root !== null) {
                removeStorageTestRoot($root);
            }
        }
    })->with('stores')->with([
        'traversal' => ['avatars/../../etc/passwd'],
        'absolute' => ['/etc/passwd'],
        'a dot segment' => ['avatars/./a.png'],
        'a backslash' => ['avatars\\a.png'],
        'an empty segment' => ['avatars//a.png'],
        'upper case' => ['Avatars/a.png'],
        'a null byte' => ["avatars/a.png\0.jpg"],
        'a trailing slash' => ['avatars/'],
        'two extensions' => ['avatars/a.php.png'],
        'nothing' => [''],
    ]);
});

describe('LocalStorage', function () {
    it('keeps its root and its directories to this user, and its files 0600', function () {
        $root = storageTestRoot();

        try {
            $storage = new LocalStorage($root);
            $key = StorageKey::generate('avatars', 'png');
            $storage->put($key, storageStreamOf('png'));

            expect(fileperms($root) & 0o777)->toBe(0o700)
                ->and(fileperms(dirname($root . '/' . $key)) & 0o777)->toBe(0o700)
                ->and(fileperms($root . '/' . $key) & 0o777)->toBe(0o600)
                // The write went through a temporary file, and nothing of it is left.
                ->and(glob($root . '/avatars/.tmp-*'))->toBe([]);
        } finally {
            removeStorageTestRoot($root);
        }
    });

    it('refuses a relative root', function () {
        expect(fn () => new LocalStorage('storage/uploads'))->toThrow(InvalidArgumentException::class, 'absolute');
    });

    it('will not write or read through a symlink planted inside its root', function () {
        $root = storageTestRoot();
        $outside = storageTestRoot();

        try {
            mkdir($outside, 0o700, true);
            file_put_contents($outside . '/secret.png', 'outside the store');
            $storage = new LocalStorage($root);
            symlink($outside, $root . '/avatars');

            $key = 'avatars/secret.png';
            expect(fn () => $storage->read($key))->toThrow(StorageException::class)
                ->and(fn () => $storage->put('avatars/' . str_repeat('a', 32) . '.png', storageStreamOf('x')))->toThrow(StorageException::class)
                ->and(fn () => $storage->delete($key))->toThrow(StorageException::class)
                ->and(file_get_contents($outside . '/secret.png'))->toBe('outside the store')
                ->and(glob($outside . '/*'))->toBe([$outside . '/secret.png']);
        } finally {
            removeStorageTestRoot($root);
            removeStorageTestRoot($outside);
        }
    });
});

describe('StorageKey', function () {
    it('makes keys under a prefix from 128 random bits and an extension, nothing a client chose', function () {
        $a = StorageKey::generate('avatars', 'png');
        $b = StorageKey::generate('avatars', 'png');

        expect($a)->toMatch('#^avatars/[a-f0-9]{32}\.png$#')
            ->and($a)->not->toBe($b)
            ->and(fn () => StorageKey::generate('../avatars', 'png'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => StorageKey::generate('avatars', 'p/ng'))->toThrow(StorageException::class);
    });
});
