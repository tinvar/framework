<?php

declare(strict_types=1);

namespace Tinvar\Discovery;

use Tinvar\Support\Posix;

/**
 * The discovered classes, written into the build (ADR 0146): what a
 * production Kernel boots from, so it never walks its sources.
 *
 * A PHP file returning an array, so OPcache holds it and reading it costs
 * nothing a worker would notice:
 *
 *     return ['version' => 1, 'namespace' => 'Shop', 'classes' => ['routes' => [...], ...]];
 *
 * Read back fail closed: a file that is missing, returns anything else,
 * names another application's namespace, or lists a class outside the
 * application's own namespace is refused whole -- a manifest is where a
 * class would be slipped in that the application never wrote, so nothing
 * in one is taken on trust but its being the build's.
 */
final class Manifest
{
    /** Its name in the build directory, beside fragments.json. */
    public const string FILE = 'discovery.php';

    public const int VERSION = 1;

    /** @var array<string, array{string, DiscoveredClasses}> file => [its stat when read, what it held] */
    private static array $read = [];

    /**
     * Writes $classes, found under $namespace, to $file: to a file beside
     * it, renamed over it, so a worker reading it never sees half. The file
     * is 0644, and its directory -- this user's -- loses any group or world
     * write a umask left it, so what read() asks of both holds for what the
     * build wrote (ADR 0218).
     */
    public static function write(string $file, string $namespace, DiscoveredClasses $classes): void
    {
        $directory = \dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not make {$directory} to write the discovery manifest in.");
        }
        clearstatcache(true, $directory);
        $mode = @fileperms($directory);
        if ($mode !== false && ($mode & 0o022) !== 0 && !is_link($directory) && fileowner($directory) === Posix::effectiveUser("the discovery manifest's directory {$directory}")) {
            @chmod($directory, $mode & 0o7755);
        }
        if (is_link($file)) {
            throw new \RuntimeException("{$file} is a symbolic link; the discovery manifest is never written through one.");
        }
        $source = "<?php\n\n"
            . "// The classes discovery found under {$namespace}\\ (ADR 0146), written by `tinvar discover` and\n"
            . "// `tinvar build`. Production boots from this file and never walks the sources; `tinvar check`\n"
            . "// fails while it differs from what a scan finds. Never edited by hand.\n\n"
            . 'return ' . self::export(['version' => self::VERSION, 'namespace' => trim($namespace, '\\'), 'classes' => $classes->toArray()]) . ";\n";
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $source) === false || !chmod($temporary, 0o644) || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new \RuntimeException("Could not write the discovery manifest to {$file}.");
        }
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        unset(self::$read[$file]);
    }

    /**
     * $value as PHP source, written as the workspace's own style writes an
     * array: short syntax, one entry to a line, four spaces a level.
     */
    private static function export(mixed $value, int $depth = 0): string
    {
        if (!\is_array($value)) {
            return var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }
        $indent = str_repeat('    ', $depth + 1);
        $list = array_is_list($value);
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = $indent . ($list ? '' : var_export($key, true) . ' => ') . self::export($item, $depth + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }

    /**
     * The classes $file lists, found under $namespace.
     *
     * @throws DiscoveryFailed where the file is missing or is not a manifest of $namespace's
     */
    public static function read(string $file, string $namespace): DiscoveredClasses
    {
        clearstatcache(true, $file);
        $stat = is_file($file) ? stat($file) : false;
        if ($stat === false) {
            throw new DiscoveryFailed(["There is no discovery manifest at {$file}."], 'Discovery refused to boot:');
        }
        self::assertTrusted($file);
        $stamp = "{$stat['ino']}:{$stat['mtime']}:{$stat['size']}";
        $kept = self::$read[$file] ?? null;
        if ($kept !== null && $kept[0] === $stamp) {
            return $kept[1];
        }

        $namespace = trim($namespace, '\\');
        $data = (static fn (string $path): mixed => include $path)($file);
        if (!\is_array($data) || ($data['version'] ?? null) !== self::VERSION || !\is_array($data['classes'] ?? null)) {
            throw new DiscoveryFailed(["{$file} is not a discovery manifest of version " . self::VERSION . '.'], 'Discovery refused to boot:');
        }
        if (!\is_string($data['namespace'] ?? null) || strcasecmp($data['namespace'], $namespace) !== 0) {
            throw new DiscoveryFailed(["{$file} lists the classes of " . var_export($data['namespace'] ?? null, true) . "\\, not {$namespace}\\'s."], 'Discovery refused to boot:');
        }
        try {
            /** @var array<string, mixed> $kinds */
            $kinds = $data['classes'];
            $classes = DiscoveredClasses::fromArray($kinds);
        } catch (\InvalidArgumentException $e) {
            throw new DiscoveryFailed(["{$file}: {$e->getMessage()}"], 'Discovery refused to boot:');
        }
        $outside = array_values(array_filter($classes->all(), static fn (string $class): bool => !str_starts_with(strtolower($class), strtolower($namespace) . '\\')));
        if ($outside !== []) {
            throw new DiscoveryFailed(["{$file} lists " . implode(', ', $outside) . ", outside {$namespace}\\: discovery registers the application's own classes alone."], 'Discovery refused to boot:');
        }

        self::$read[$file] = [$stamp, $classes];

        return $classes;
    }

    /**
     * The manifest is PHP, and included: whoever can write it, or replace
     * it in its directory, runs code in every worker. So it is a regular
     * file in a directory, neither a link, both owned by this user or root,
     * and neither writable by anyone but its owner -- no group, whoever is
     * in it (ADR 0218). Never skipped: where PHP cannot say who this
     * process is -- no ext-posix -- the manifest is refused (ADR 0212).
     *
     * @throws DiscoveryFailed
     */
    private static function assertTrusted(string $file): void
    {
        try {
            $user = Posix::effectiveUser("the discovery manifest {$file}");
        } catch (\RuntimeException $e) {
            throw new DiscoveryFailed([$e->getMessage()], 'Discovery refused to boot:');
        }
        foreach ([$file, \dirname($file)] as $path) {
            if (is_link($path)) {
                throw new DiscoveryFailed(["{$path} is a symbolic link; the discovery manifest is only read as the build wrote it, where it wrote it."], 'Discovery refused to boot:');
            }
        }
        $trusted = [$user, 0];
        foreach ([$file, \dirname($file)] as $path) {
            $stat = @lstat($path);
            if ($stat === false || !\in_array($stat['uid'], $trusted, true)) {
                throw new DiscoveryFailed(["{$path} is owned by someone other than this user or root: the discovery manifest is PHP, and is included."], 'Discovery refused to boot:');
            }
            if (($stat['mode'] & 0o022) !== 0) {
                throw new DiscoveryFailed(["{$path} is writable by someone other than its owner: the discovery manifest is PHP, and is included."], 'Discovery refused to boot:');
            }
        }
    }
}
