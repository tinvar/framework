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
    it('keeps what a writer was given only once it is committed, and nothing of one given up', function (Closure $make) {
        /** @var Storage $storage */
        [$storage, $root] = $make();
        $key = StorageKey::generate('images', 'iso');
        $other = StorageKey::generate('images', 'iso');

        try {
            $writer = $storage->writer($key);
            $writer->write('first ');
            $writer->write('and second');
            expect($storage->exists($key))->toBeFalse();
            $writer->commit();
            $writer->abort();
            expect(stream_get_contents($storage->read($key)))->toBe('first and second')
                ->and(fn () => $writer->write('more'))->toThrow(StorageException::class);

            $given = $storage->writer($other);
            $given->write('half');
            $given->abort();
            $given->abort();

            // Dropped without a commit, as a transfer that threw drops one.
            $dropped = $storage->writer($other);
            $dropped->write('half');
            unset($dropped);

            expect($storage->exists($other))->toBeFalse();
            if ($root !== null) {
                // Not a temporary file left beside the one that was kept.
                expect(array_values(array_diff(scandir("{$root}/images") ?: [], ['.', '..'])))->toBe([basename($key)]);
            }

            expect(fn () => $storage->writer('../escape'))->toThrow(StorageException::class);
        } finally {
            $root === null || removeStorageTestRoot($root);
        }
    })->with('stores');

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

    // Built on every boot, written to by few: a CLI command or a test that
    // never uploads leaves no directory behind.
    it('makes its root when it first writes, and holds nothing before', function () {
        $root = storageTestRoot();

        try {
            $storage = new LocalStorage($root);
            $key = StorageKey::generate('avatars', 'png');

            expect(is_dir($root))->toBeFalse()
                ->and($storage->exists($key))->toBeFalse()
                ->and($storage->read($key))->toBeNull()
                ->and(is_dir($root))->toBeFalse();

            $storage->delete($key);
            $storage->put($key, storageStreamOf('png'));
            expect(is_dir($root))->toBeTrue();
        } finally {
            removeStorageTestRoot($root);
        }
    });

    it('will not write or read through a symlink planted inside its root', function () {
        $root = storageTestRoot();
        $outside = storageTestRoot();

        try {
            mkdir($outside, 0o700, true);
            mkdir($root, 0o700, true);
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
