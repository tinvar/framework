<?php

declare(strict_types=1);

namespace Tinvar\Dev;

/**
 * Where the dev toolbar keeps the requests it recorded, between the request
 * that made one and the toolbar's fetch for it: files in the private
 * directory `tinvar dev` made for this run.
 *
 * Files, because dev serves every request from a fresh PHP (FrankenPHP in
 * classic mode, ADR 0034): nothing in memory outlives the request that
 * recorded it. One file a request, its summary on the first line and the
 * whole on the second, so listing reads a line of each. Written whole and
 * renamed into place, so a reader never sees half of one; 0600, in a 0700
 * directory, so no other user reads what a page was given.
 *
 * Each is kept under the keyed hashes of the session and the user it was
 * recorded for, and handed back only to a request presenting the same --
 * never the hashes themselves, and never another session's. The newest KEEP
 * are kept; older ones are removed as new ones arrive.
 */
final class RecordingStore
{
    /** Requests kept, across every session of the run. */
    public const int KEEP = 60;

    /** Requests one listing returns, newest first. */
    public const int LIST = 40;

    private const string NAME = '/^r-[0-9]{17}-([0-9a-f]{16})\.json\z/';

    /** A summary line is small; anything longer is not one this wrote. */
    private const int SUMMARY_BYTES = 8192;

    public function __construct(private readonly string $directory) {}

    /** @param array{summary: array<string, mixed>, detail: array<string, mixed>} $recording */
    public function save(array $recording): void
    {
        $id = $recording['summary']['id'] ?? null;
        if (!\is_string($id) || preg_match('/^[0-9a-f]{16}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('A recording is saved under the id it was given.');
        }

        $flags = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;
        $contents = json_encode($recording['summary'], $flags) . "\n" . json_encode($recording['detail'], $flags) . "\n";

        // Named by when, so a sort by name is a sort by age.
        $name = \sprintf('r-%017d-%s.json', (int) (microtime(true) * 1e6), $id);
        $temporary = "{$this->directory}/.{$name}." . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write a recording into {$this->directory}.");
        }
        chmod($temporary, 0600);
        if (!rename($temporary, "{$this->directory}/{$name}")) {
            @unlink($temporary);

            throw new \RuntimeException("Could not write a recording into {$this->directory}.");
        }

        $this->prune();
    }

    /**
     * The summaries of the requests recorded for this binding, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $sessionHash, string $authHash, int $limit = self::LIST): array
    {
        $found = [];
        foreach (array_reverse($this->files()) as $file) {
            $summary = $this->summary($file, $sessionHash, $authHash);
            if ($summary === null) {
                continue;
            }
            $found[] = $summary;
            if (\count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * One request recorded for this binding, whole; null when there is no
     * such request, or it is another's -- which a caller cannot tell apart.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $sessionHash, string $authHash): ?array
    {
        if (preg_match('/^[0-9a-f]{16}\z/', $id) !== 1) {
            return null;
        }

        foreach ($this->files() as $file) {
            if (preg_match(self::NAME, basename($file), $m) !== 1 || $m[1] !== $id) {
                continue;
            }
            if ($this->summary($file, $sessionHash, $authHash) === null) {
                return null;
            }

            $handle = @fopen($file, 'r');
            if ($handle === false) {
                return null;
            }
            try {
                fgets($handle, self::SUMMARY_BYTES);
                $detail = json_decode((string) stream_get_contents($handle), true);
            } finally {
                fclose($handle);
            }

            /** @var array<string, mixed>|null */
            return \is_array($detail) ? $detail : null;
        }

        return null;
    }

    /**
     * The summary in $file when it was recorded for this binding, without the
     * binding.
     *
     * @return array<string, mixed>|null
     */
    private function summary(string $file, string $sessionHash, string $authHash): ?array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            // Removed by a newer request's pruning since it was listed.
            return null;
        }
        try {
            $line = fgets($handle, self::SUMMARY_BYTES);
        } finally {
            fclose($handle);
        }

        $summary = \is_string($line) ? json_decode($line, true) : null;
        if (!\is_array($summary)
            || !\is_string($summary['session'] ?? null) || !hash_equals($summary['session'], $sessionHash)
            || !\is_string($summary['auth'] ?? null) || !hash_equals($summary['auth'], $authHash)) {
            return null;
        }
        unset($summary['session'], $summary['auth']);

        /** @var array<string, mixed> */
        return $summary;
    }

    /** @return list<string> every recording, oldest first */
    private function files(): array
    {
        $files = array_values(array_filter(
            glob("{$this->directory}/r-*.json") ?: [],
            static fn (string $file): bool => preg_match(self::NAME, basename($file)) === 1 && is_file($file) && !is_link($file),
        ));
        sort($files, SORT_STRING);

        return $files;
    }

    private function prune(): void
    {
        $files = $this->files();
        foreach (\array_slice($files, 0, max(0, \count($files) - self::KEEP)) as $old) {
            // Another request may be pruning the same file.
            @unlink($old);
        }
    }
}
