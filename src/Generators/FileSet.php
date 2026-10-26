<?php

declare(strict_types=1);

namespace Tinvar\Generators;

/**
 * The files one generator run writes: all of them, or none.
 *
 * A page is three files and a model with its factory two; one written
 * without the others is a class that names a class that is not there. So
 * every path is checked before the first is written, and a write that fails
 * part-way takes back the files it made.
 *
 * Nothing is ever replaced. The check before writing says which files are
 * in the way; the write itself opens each file exclusively (`x`), so one
 * that appears in between -- or a symbolic link left where a file would go
 * -- fails the write rather than being written through.
 */
final class FileSet
{
    /** @var array<string, string> path => contents */
    private array $files = [];

    public function add(string $path, string $contents): self
    {
        if (isset($this->files[$path])) {
            throw new \LogicException("{$path} would be written twice.");
        }
        $this->files[$path] = $contents;

        return $this;
    }

    /**
     * Refuses, naming each, if any of $paths is there already: one this run
     * does not write but whose presence would make what it writes ambiguous,
     * such as racks/show.jsx beside a new racks/show.tsx.
     *
     * @param list<string> $paths
     * @param \Closure(string): string $show how a path is named to the developer
     */
    public static function assertAbsent(array $paths, \Closure $show): void
    {
        $present = array_values(array_filter($paths, static fn (string $path): bool => file_exists($path) || is_link($path)));
        if ($present !== []) {
            throw GenerationRefused::exists(array_map($show, $present));
        }
    }

    /**
     * Writes every file, making the directories they go in.
     *
     * @param \Closure(string): string $show how a path is named to the developer
     * @return list<string> the paths written, in the order they were added
     */
    public function write(\Closure $show): array
    {
        self::assertAbsent(array_keys($this->files), $show);

        foreach (array_keys($this->files) as $path) {
            for ($directory = \dirname($path); !is_dir($directory); $directory = \dirname($directory)) {
                if (file_exists($directory) || is_link($directory)) {
                    throw new GenerationRefused("{$show($directory)} is a file, where {$show($path)} needs a directory. Nothing was written.");
                }
            }
        }

        $written = [];
        $made = [];
        try {
            foreach ($this->files as $path => $contents) {
                $made = [...$made, ...self::directoriesFor(\dirname($path))];

                $handle = @fopen($path, 'x');
                if ($handle === false) {
                    throw new GenerationRefused("Could not create {$show($path)}: " . (file_exists($path) || is_link($path) ? 'it appeared while the others were written' : 'it could not be opened') . '. Nothing was written.');
                }
                $written[] = $path;
                $ok = fwrite($handle, $contents) === \strlen($contents);
                $ok = fclose($handle) && $ok;
                if (!$ok) {
                    throw new GenerationRefused("Could not write {$show($path)}. Nothing was written.");
                }
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($written) as $path) {
                @unlink($path);
            }
            foreach (array_reverse($made) as $directory) {
                @rmdir($directory);
            }

            throw $e;
        }

        return $written;
    }

    /**
     * Makes $directory and whatever above it is missing.
     *
     * @return list<string> those made, outermost first
     */
    private static function directoriesFor(string $directory): array
    {
        $missing = [];
        for ($at = $directory; !is_dir($at); $at = \dirname($at)) {
            array_unshift($missing, $at);
        }
        foreach ($missing as $at) {
            if (!@mkdir($at, 0o755) && !is_dir($at)) {
                throw new GenerationRefused("Could not create {$at}. Nothing was written.");
            }
        }

        return $missing;
    }
}
