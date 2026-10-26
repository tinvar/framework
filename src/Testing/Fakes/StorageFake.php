<?php

declare(strict_types=1);

namespace Tinvar\Testing\Fakes;

use PHPUnit\Framework\Assert;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Storage\Storage;
use Tinvar\Storage\StorageKey;

/**
 * The storage fake: hand it to the application as the Storage its uploads
 * are kept in (ADR 0037), and every file is kept in memory, where a test asks
 * what is there -- at which key, under which prefix, with which bytes.
 *
 * Everything else is a MemoryStorage's, so a key the real stores refuse is
 * refused here too: a test cannot pass with a key LocalStorage would not
 * take. A test that reads a key back from a row -- `avatar_key` -- asks about
 * it by that key; one that never sees the key, since the framework draws it
 * at random, asks by the prefix the action declared.
 */
final class StorageFake implements Storage
{
    private readonly MemoryStorage $files;

    public function __construct()
    {
        $this->files = new MemoryStorage();
    }

    public function put(string $key, mixed $stream): void
    {
        $this->files->put($key, $stream);
    }

    public function read(string $key): mixed
    {
        return $this->files->read($key);
    }

    public function exists(string $key): bool
    {
        return $this->files->exists($key);
    }

    public function size(string $key): ?int
    {
        return $this->files->size($key);
    }

    public function delete(string $key): void
    {
        $this->files->delete($key);
    }

    /**
     * Every key a file is held at -- under $prefix, given one -- sorted.
     *
     * @return list<string>
     */
    public function keys(?string $prefix = null): array
    {
        $keys = $this->files->keys();
        if ($prefix !== null) {
            $keys = array_values(array_filter($keys, static fn (string $key): bool => self::isUnder($key, $prefix)));
        }
        sort($keys);

        return $keys;
    }

    /** What is held at $key, or null when nothing is. */
    public function contents(string $key): ?string
    {
        StorageKey::assertValid($key);

        return $this->files->contents($key);
    }

    /** A file is held at $key. */
    public function assertExists(string $key): self
    {
        Assert::assertTrue($this->exists($key), "No file is stored at '{$key}'." . $this->summary());

        return $this;
    }

    /** No file is held at $key: never stored, or deleted since. */
    public function assertMissing(string $key): self
    {
        Assert::assertFalse($this->exists($key), "A file is stored at '{$key}'.");

        return $this;
    }

    /** Exactly $count files are held -- under $prefix, given one. */
    public function assertCount(int $count, ?string $prefix = null): self
    {
        $keys = $this->keys($prefix);
        Assert::assertCount($count, $keys, \count($keys) . ' files are stored' . ($prefix === null ? '' : " under '{$prefix}'") . ", not {$count}." . $this->summary());

        return $this;
    }

    public function assertNothingStored(): self
    {
        return $this->assertCount(0);
    }

    /**
     * The file at $key holds $expected -- those bytes, or bytes the closure
     * returns true for.
     *
     * @param string|(\Closure(string): bool) $expected
     */
    public function assertContents(string $key, string|\Closure $expected): self
    {
        $this->assertExists($key);
        $contents = (string) $this->contents($key);

        if ($expected instanceof \Closure) {
            Assert::assertTrue($expected($contents) === true, "The file at '{$key}' holds bytes the closure marked wrong.");
        } else {
            // Compared by hash: the bytes are an image's as often as not, and
            // a diff of them helps nobody.
            Assert::assertSame(hash('sha256', $expected), hash('sha256', $contents), "The file at '{$key}' holds other bytes: "
                . \strlen($contents) . ' of them, where ' . \strlen($expected) . ' were expected.');
        }

        return $this;
    }

    /**
     * A file was stored under the key prefix $prefix -- the one an upload's
     * #[Uploaded] declares: the file at $key, given one, and otherwise any.
     */
    public function assertStoredUnder(string $prefix, ?string $key = null): self
    {
        if (!StorageKey::isValidPrefix($prefix)) {
            throw new \InvalidArgumentException("'{$prefix}' is not a storage key prefix.");
        }

        if ($key === null) {
            Assert::assertNotSame([], $this->keys($prefix), "No file is stored under '{$prefix}'." . $this->summary());

            return $this;
        }

        $this->assertExists($key);
        Assert::assertTrue(self::isUnder($key, $prefix), "The file at '{$key}' is not stored under '{$prefix}'.");

        return $this;
    }

    /**
     * Whether $key is under $prefix: the prefix's segments, then its own name
     * -- `avatars/4f1c….png` is under `avatars`, and not under `avatar`.
     */
    private static function isUnder(string $key, string $prefix): bool
    {
        return str_starts_with($key, rtrim($prefix, '/') . '/');
    }

    private function summary(): string
    {
        $keys = $this->keys();

        return $keys === [] ? ' Nothing is stored.' : ' Stored: ' . implode(', ', $keys) . '.';
    }
}
