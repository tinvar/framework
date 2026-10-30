<?php

declare(strict_types=1);

namespace Tinvar\Server;

/**
 * Notices when an application's PHP changes, for `tinvar dev` to run codegen
 * ("Codegen runs on file change, so TypeScript types update when a Data
 * object, query or action changes").
 *
 * By polling: each look lists the `.php` files under the directories and
 * compares their modification times and sizes with the last look. PHP has no
 * portable file notification, FrankenPHP's watcher is not in the binary the
 * shop uses (ADR 0034), and an application's sources are a few hundred
 * files, which a look every half second reads in a millisecond or two.
 *
 * Settled before it reports: an editor saving a file, or a branch checked
 * out, is several writes in a row, and codegen run in the middle of them
 * reads half an edit. So a change is reported only on the first look that
 * finds nothing newer than the look before.
 */
final class SourceWatcher
{
    /** Seconds within which a file's contents are read as well as its time and size. */
    private const int RECENT = 3;

    /** @var array<string, string> path => modification time, size and a hash of the contents */
    private array $seen = [];

    /**
     * The files changed since the last report, waiting for the writes to stop.
     *
     * @var array<string, true>
     */
    private array $pending = [];

    /** Directories never walked: what Composer and npm install, and what version control keeps. */
    private const array SKIPPED = ['vendor', 'node_modules'];

    /** @param list<string> $directories the directories to watch, and any single files beside them */
    public function __construct(private readonly array $directories)
    {
        $this->seen = $this->look();
    }

    /**
     * The files that changed -- written, added or removed -- once they have
     * settled; [] while nothing has, or while writes are still arriving.
     *
     * @return list<string>
     */
    public function changes(): array
    {
        $now = $this->look();
        $changed = array_keys(array_diff_assoc($now, $this->seen) + array_diff_key($this->seen, $now));
        $this->seen = $now;

        if ($changed !== []) {
            foreach ($changed as $path) {
                $this->pending[$path] = true;
            }

            return [];
        }

        $settled = array_keys($this->pending);
        $this->pending = [];
        sort($settled);

        /** @var list<string> */
        return $settled;
    }

    /** @return array<string, string> */
    private function look(): array
    {
        $files = [];
        foreach ($this->directories as $directory) {
            if (is_file($directory)) {
                $this->stamp($directory, $files);
                continue;
            }
            if (!is_dir($directory)) {
                continue;
            }
            // An autoload root can be the application's own directory, so
            // what Composer and npm installed there, and dot-directories, are
            // not walked: they are not the application's PHP.
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !$file->isDir()
                    || (!\in_array($file->getFilename(), self::SKIPPED, true) && !str_starts_with($file->getFilename(), '.')),
            ));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $this->stamp($file->getPathname(), $files);
                }
            }
        }

        return $files;
    }

    /**
     * $path's time, size and -- while it is recent -- contents, into $files.
     *
     * @param array<string, string> $files
     */
    private function stamp(string $path, array &$files): void
    {
        // Read now, and the file gone before it could be: it is simply not
        // there on this look.
        $stat = @stat($path);
        if ($stat === false) {
            return;
        }
        // Times are whole seconds, so two saves of the same size in one
        // second look alike: what the file holds is read as well while it
        // is recent, or new, or its time or size moved -- and otherwise
        // carried over from the last look, so a file leaving the recent
        // window is no change.
        $stamp = $stat['mtime'] . ':' . $stat['size'];
        $last = $this->seen[$path] ?? '';
        $files[$path] = $stat['mtime'] < time() - self::RECENT && str_starts_with($last, "{$stamp}:")
            ? $last
            : $stamp . ':' . hash('xxh3', (string) @file_get_contents($path));
    }
}
