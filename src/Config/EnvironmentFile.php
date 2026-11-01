<?php

declare(strict_types=1);

namespace Tinvar\Config;

use Tinvar\Support\BuildProfile;
use Tinvar\Support\DevMode;

/**
 * An application's `.env`, read into a developer's process environment
 * before anything reads config (ADR 0150): what `tinvar new` writes the
 * settings it asked about into -- the database, the mail provider, the
 * hosts -- so `bin/dev` and `bin/tinvar` run with them without anyone
 * exporting a variable by hand.
 *
 * Config is still read from the environment and nowhere else (ADR 0085):
 * this only fills the environment, and only on a developer's machine.
 *
 * - **Never in production or staging.** Where TINVAR_ENV, APP_ENV or
 *   NODE_ENV says production, TINVAR_ENV or APP_ENV says staging, or
 *   TINVAR_PROFILE is set, nothing is read: a server's settings are its
 *   environment's -- a platform's secret store, a systemd unit, compose --
 *   and the image leaves `.env` out (`.dockerignore`).
 * - **Never over the environment.** A variable already set, even to the
 *   empty string, is left as it is: what a person exports wins.
 * - **Never a secret.** A variable whose name says it holds one -- ending
 *   in `_KEY`, `_SECRET`, `_PASSWORD` or `_TOKEN` -- is not read from a
 *   file inside the project, where a copy, an archive or a commit carries
 *   it away. Its `_FILE` variable, naming a file outside the project, is
 *   read like any other. `tinvar doctor` names each one it finds.
 * - **Never a guess.** A line that is not blank, a `#` comment or
 *   `NAME=value` stops the process with its line number, never its text.
 *   A value may be quoted in `"` or `'`, and is otherwise taken as written
 *   up to an unquoted ` #`; nothing is interpolated.
 */
final class EnvironmentFile
{
    /** The suffixes of a variable this never reads from a file in the project. */
    public const array SECRET_SUFFIXES = ['_KEY', '_SECRET', '_PASSWORD', '_TOKEN'];

    /**
     * Reads $file into the process environment, as the class says; nothing
     * where it is missing or the environment says production or staging.
     *
     * @return array{loaded: list<string>, secrets: list<string>} the variables set, and the
     *         secrets the file holds that were left out
     * @throws ConfigException for a line that cannot be read
     */
    public static function load(string $file): array
    {
        $environment = array_filter(getenv(), \is_string(...));
        if (!is_file($file) || self::refusedHere($environment) !== null) {
            return ['loaded' => [], 'secrets' => []];
        }

        $read = self::parse((string) file_get_contents($file), $file);
        $loaded = [];
        foreach ($read['values'] as $name => $value) {
            if (\array_key_exists($name, $environment) || $value === '') {
                continue;
            }
            putenv("{$name}={$value}");
            $loaded[] = $name;
        }

        return ['loaded' => $loaded, 'secrets' => $read['secrets']];
    }

    /**
     * What makes this environment one a `.env` is never read in, as a
     * variable and its value, `TINVAR_ENV=production`; null on a
     * developer's machine.
     *
     * @param array<string, string> $environment
     */
    public static function refusedHere(array $environment): ?string
    {
        $marker = DevMode::productionMarker($environment);
        if ($marker !== null) {
            return "{$marker}=production";
        }
        foreach (['TINVAR_ENV', 'APP_ENV'] as $variable) {
            if (strtolower(trim($environment[$variable] ?? '')) === BuildProfile::STAGING) {
                return "{$variable}=staging";
            }
        }
        if (trim($environment[BuildProfile::ENV] ?? '') !== '') {
            return BuildProfile::ENV . '=' . trim($environment[BuildProfile::ENV]);
        }

        return null;
    }

    /** Whether $name is a variable this never reads from a file inside the project. */
    public static function namesSecret(string $name): bool
    {
        foreach (self::SECRET_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The file's values, the secrets left out of them, and any line that
     * cannot be read refused by its number.
     *
     * @return array{values: array<string, string>, secrets: list<string>}
     * @throws ConfigException
     */
    public static function parse(string $contents, string $file = '.env'): array
    {
        $values = [];
        $secrets = [];
        $problems = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $index => $line) {
            $number = $index + 1;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Z][A-Z0-9_]*)=(.*)\z/', $line, $m) !== 1) {
                $problems[] = "{$file} line {$number} is not NAME=value: write a variable in capitals, an equals sign and its value, or make it a # comment.";

                continue;
            }
            $value = self::value($m[2]);
            if ($value === null) {
                $problems[] = "{$file} line {$number} opens a quote it does not close.";

                continue;
            }
            if (self::namesSecret($m[1])) {
                if ($value !== '') {
                    $secrets[] = $m[1];
                }

                continue;
            }
            $values[$m[1]] = $value;
        }
        if ($problems !== []) {
            throw new ConfigException($problems);
        }

        return ['values' => $values, 'secrets' => $secrets];
    }

    /** A value as written: unquoted, or without the comment after it; null for a quote left open. */
    private static function value(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $end = strpos($raw, $raw[0], 1);
            if ($end === false) {
                return null;
            }

            return substr($raw, 1, $end - 1);
        }
        $comment = strpos($raw, ' #');

        return rtrim($comment === false ? $raw : substr($raw, 0, $comment));
    }
}
