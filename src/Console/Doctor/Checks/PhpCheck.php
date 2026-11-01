<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Orm\DatabaseDriver;

/**
 * PHP 8.5, and every extension the application needs: what its own
 * composer.json and every package its composer.lock installs require
 * (`ext-*`), what `tinvar serve`, the queue worker and the renderers'
 * sockets need (sockets, pcntl, posix), and the PDO driver of the database
 * APP_DATABASE_DRIVER names.
 */
final readonly class PhpCheck implements DoctorCheck
{
    /** The oldest PHP the framework runs on. */
    public const string MINIMUM = '8.5.0';

    /** What the CLI needs beside what Composer's manifests say: serve, the queue worker, the sockets. */
    public const array ALWAYS = ['sockets', 'pcntl', 'posix'];

    /**
     * @param string|null $version a test's PHP version; this process's by default
     * @param list<string>|null $loaded a test's loaded extensions; this process's by default
     */
    public function __construct(private ?string $version = null, private ?array $loaded = null) {}

    public function title(): string
    {
        return 'PHP and its extensions';
    }

    public function run(DoctorContext $context): array
    {
        $findings = [];
        $version = $this->version ?? PHP_VERSION;
        if (version_compare($version, self::MINIMUM, '<')) {
            $findings[] = Finding::problem(
                "PHP is {$version}; Tinvar needs " . self::MINIMUM . ' or later.',
                'Install PHP 8.5 for the command line, and put it first on your PATH (`php --version` says which runs).',
            );
        }

        $loaded = array_map(strtolower(...), $this->loaded ?? get_loaded_extensions());
        $needed = [];
        foreach (self::ALWAYS as $extension) {
            $needed[$extension] = 'tinvar serve and the queue worker';
        }
        foreach (self::required($context->projectRoot()) as $extension => $by) {
            $needed[$extension] ??= $by;
        }
        $driver = DatabaseDriver::tryFrom(strtolower($context->variable('APP_DATABASE_DRIVER') ?? 'sqlite')) ?? DatabaseDriver::Sqlite;
        $needed[$driver->extension()] ??= "the {$driver->title()} database (APP_DATABASE_DRIVER)";
        ksort($needed);

        $missing = array_diff_key($needed, array_flip($loaded));
        foreach ($missing as $extension => $by) {
            $findings[] = Finding::problem(
                "The {$extension} extension is not loaded, and {$by} needs it.",
                "Install and enable it for PHP {$version}: your package manager's php-{$extension}, or `pecl install {$extension}`; then `php -m` lists it.",
            );
        }
        if ($findings === []) {
            $findings[] = Finding::passed("PHP {$version}, with " . implode(', ', array_keys($needed)) . '.');
        }

        return $findings;
    }

    /**
     * Each extension the application's composer.json and every package in
     * its composer.lock require, and who requires it.
     *
     * @return array<string, string>
     */
    public static function required(string $projectRoot): array
    {
        $required = [];
        $read = static function (array $requires, string $by) use (&$required): void {
            foreach (array_keys($requires) as $package) {
                if (\is_string($package) && str_starts_with($package, 'ext-')) {
                    $required[strtolower(substr($package, 4))] ??= $by;
                }
            }
        };
        $manifest = json_decode((is_file("{$projectRoot}/composer.json") ? (string) file_get_contents("{$projectRoot}/composer.json") : ''), true);
        if (\is_array($manifest) && \is_array($manifest['require'] ?? null)) {
            $read($manifest['require'], 'composer.json');
        }
        $lock = json_decode((is_file("{$projectRoot}/composer.lock") ? (string) file_get_contents("{$projectRoot}/composer.lock") : ''), true);
        foreach (\is_array($lock) && \is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
            if (\is_array($package) && \is_array($package['require'] ?? null)) {
                $read($package['require'], (string) ($package['name'] ?? 'a package'));
            }
        }

        return $required;
    }
}
