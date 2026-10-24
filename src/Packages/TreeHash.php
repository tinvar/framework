<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * A hash of an installed package's files, the way Go's go.sum pins a module.
 *
 * Archive hashes are what most lockfiles record, but Composer records none
 * for a GitHub-hosted package (its `shasum` is empty), and GitHub does not
 * promise an archive's bytes stay the same. What is installed is what runs,
 * so that is what this hashes: every file's path and SHA-256, sorted, hashed
 * again. A symbolic link is hashed as its target, never followed, so a link
 * cannot pull a file from outside the package into the hash -- or change
 * what it points at without changing it.
 */
final class TreeHash
{
    public const string PREFIX = 'h1:';

    public static function of(string $directory): string
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("{$directory} is not a directory.");
        }

        $lines = [];
        foreach (self::files($real) as $relative => $line) {
            $lines[$relative] = $line;
        }
        ksort($lines, \SORT_STRING);

        return self::PREFIX . base64_encode(hash('sha256', implode('', $lines), true));
    }

    /** @return \Generator<string, string> */
    private static function files(string $root, string $prefix = ''): \Generator
    {
        $entries = scandir($root . ($prefix === '' ? '' : '/' . $prefix));
        if ($entries === false) {
            throw new \RuntimeException("Cannot read {$root}/{$prefix}.");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            $path = $root . '/' . $relative;

            if (is_link($path)) {
                yield $relative => 'link:' . hash('sha256', (string) readlink($path)) . '  ' . rawurlencode($relative) . "\n";
            } elseif (is_dir($path)) {
                yield from self::files($root, $relative);
            } else {
                $hash = hash_file('sha256', $path);
                if ($hash === false) {
                    throw new \RuntimeException("Cannot read {$path}.");
                }
                yield $relative => $hash . '  ' . rawurlencode($relative) . "\n";
            }
        }
    }
}
