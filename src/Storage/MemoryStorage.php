<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * Files in this process's memory: for tests, and nothing a second process
 * or the next request on a php -S server can see. Keys are held to the same
 * shape as on disk, so a test cannot pass with a key the real store refuses.
 */
final class MemoryStorage implements Storage
{
    /** @var array<string, string> key => contents */
    private array $files = [];

    public function put(string $key, mixed $stream): void
    {
        StorageKey::assertValid($key);
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('Storage::put() takes a readable stream.');
        }

        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new StorageException('A stored file could not be read to the end.');
        }

        $this->files[$key] = $contents;
    }

    public function read(string $key): mixed
    {
        StorageKey::assertValid($key);
        if (!isset($this->files[$key])) {
            return null;
        }

        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new StorageException('A memory stream cannot be opened.');
        }
        fwrite($stream, $this->files[$key]);
        rewind($stream);

        return $stream;
    }

    public function exists(string $key): bool
    {
        StorageKey::assertValid($key);

        return isset($this->files[$key]);
    }

    public function size(string $key): ?int
    {
        StorageKey::assertValid($key);

        return isset($this->files[$key]) ? \strlen($this->files[$key]) : null;
    }

    public function delete(string $key): void
    {
        StorageKey::assertValid($key);
        unset($this->files[$key]);
    }

    /** @return list<string> every key held, for a test to look at */
    public function keys(): array
    {
        // As strings: PHP turns a key of digits alone into an integer.
        return array_map(strval(...), array_keys($this->files));
    }

    /** What is held at $key, for a test to look at; null when nothing is. */
    public function contents(string $key): ?string
    {
        return $this->files[$key] ?? null;
    }
}
