<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

/**
 * The file edits `tinvar new` makes in the application it writes: its
 * manifests, its `.env`, and a class edited at a line the skeleton is known
 * to have -- refused, naming the file and the line, where the skeleton no
 * longer has it, never edited somewhere near it.
 */
final class AppFiles
{
    /** @return array<string, mixed> */
    public static function readJson(string $file): array
    {
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!\is_array($decoded)) {
            throw new \RuntimeException("{$file} is not a JSON object.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $value */
    public static function writeJson(string $file, array $value): void
    {
        file_put_contents($file, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    /** The constraint the application requires the framework at, `0.4.*`: its siblings' too. */
    public static function constraint(string $directory): string
    {
        $require = self::readJson("{$directory}/composer.json")['require'] ?? [];
        $constraint = \is_array($require) ? ($require['tinvar/framework'] ?? null) : null;

        return \is_string($constraint) ? $constraint : throw new \RuntimeException("{$directory}/composer.json does not require tinvar/framework.");
    }

    /** @param array<string, string> $packages package => constraint */
    public static function requirePackages(string $directory, array $packages): void
    {
        $manifest = self::readJson("{$directory}/composer.json");
        foreach ($packages as $package => $constraint) {
            $manifest['require'][$package] = $constraint;
        }
        ksort($manifest['require']);
        self::writeJson("{$directory}/composer.json", $manifest);
    }

    /**
     * Replaces $anchor, which $file must hold exactly once, with $replacement.
     *
     * @throws \RuntimeException where it holds it no times or more than once
     */
    public static function edit(string $file, string $anchor, string $replacement): void
    {
        $contents = (string) file_get_contents($file);
        $count = substr_count($contents, $anchor);
        if ($count !== 1) {
            throw new \RuntimeException("{$file} holds `" . strtok(trim($anchor), "\n") . "` {$count} times, not once: the skeleton has changed under `tinvar new`, which edits it there. Make the change by hand, or report it.");
        }
        file_put_contents($file, str_replace($anchor, $replacement, $contents));
    }

    /** Writes $file, refusing one that is there: a new application has none. */
    public static function create(string $file, string $contents): void
    {
        if (file_exists($file)) {
            throw new \RuntimeException("{$file} is already there.");
        }
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o755, true);
        }
        file_put_contents($file, $contents);
    }
}
