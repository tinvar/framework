<?php

declare(strict_types=1);

namespace Tinvar\Server;

/**
 * The command that runs a PHP script with the PHP this process is.
 *
 * Usually that is PHP_BINARY. Under FrankenPHP's static binary it is not:
 * the binary is PHP's CLI only as `frankenphp php-cli`, and PHP_BINARY names
 * the binary itself, so `[PHP_BINARY, 'script.php']` asked FrankenPHP for a
 * subcommand called script.php and failed. Where the static binary is the
 * only PHP there is, every child `tinvar serve` starts (the tether, the
 * queue worker, the scheduler) went that way. The container image no longer
 * is such a place: it runs serve on PHP's own CLI, whose children are PHP's
 * own CLI too, since the static binary's cannot take a signal (ADR 0063).
 *
 * Told apart by what the process was started as, where Linux says
 * (/proc/self/cmdline: `<binary> php-cli ...`), else by the binary's name,
 * which the static binary keeps as frankenphp wherever it is installed;
 * FrankenPHP has no other way in to its CLI, so nothing named php runs it.
 */
final class PhpCommand
{
    /**
     * The command's start: [PHP_BINARY], or [PHP_BINARY, 'php-cli'].
     *
     * @return non-empty-list<string>
     */
    public static function prefix(): array
    {
        return self::prefixFor(PHP_BINARY, self::commandLine());
    }

    /**
     * @param list<string>|null $commandLine this process's arguments as the
     *                                       kernel has them, or null where it does not say
     * @return non-empty-list<string>
     */
    public static function prefixFor(string $binary, ?array $commandLine): array
    {
        $frankenphp = $commandLine !== null
            ? ($commandLine[1] ?? null) === 'php-cli'
            : str_starts_with(strtolower(basename($binary)), 'frankenphp');

        return $frankenphp ? [$binary, 'php-cli'] : [$binary];
    }

    /** @return list<string>|null */
    private static function commandLine(): ?array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/self/cmdline')) {
            return null;
        }
        $read = @file_get_contents('/proc/self/cmdline');
        if (!\is_string($read) || $read === '') {
            return null;
        }

        return explode("\0", rtrim($read, "\0"));
    }
}
