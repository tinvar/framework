<?php

declare(strict_types=1);

namespace Tinvar\Console\Secrets;

use Tinvar\Support\AppKey;

/**
 * The secrets an application generates for itself on a developer's
 * machine -- the app key, the probe token -- each into its file outside
 * the project (ADR 0082), as AppKey writes them: 0600 from the first byte,
 * under a lock, renamed into place whole. What `key:generate`, `doctor
 * --fix` and `tinvar new` generate them with, so each writes the file the
 * app itself would have (ADR 0156).
 */
final class SecretFiles
{
    /** The app key's variable. */
    public const string APP_KEY = 'TINVAR_APP_KEY';

    /** The probe token's variable. */
    public const string PROBE_TOKEN = 'TINVAR_PROBE_TOKEN';

    /**
     * Generates the secret $variable would hold into $file, where neither
     * $variable nor the file has one; says which it did, never the secret.
     *
     * @param array<string, string>|null $env the environment $variable is read from; the process's own by default
     * @return 'environment'|'kept'|'generated'
     * @throws \RuntimeException where the file cannot be written privately
     */
    public static function ensure(string $variable, string $file, ?array $env = null): string
    {
        $given = $env === null ? getenv($variable) : ($env[$variable] ?? false);
        if (\is_string($given) && trim($given) !== '') {
            return 'environment';
        }
        if (!str_starts_with($file, '/')) {
            throw new \RuntimeException("{$file} is not an absolute path: a secret's file is named from /, or each process would look for it somewhere else.");
        }
        if (is_file($file)) {
            return 'kept';
        }
        // AppKey reads the variable first: one never set generates the file.
        AppKey::resolve($variable . '_UNSET_' . bin2hex(random_bytes(4)), $file);

        return 'generated';
    }

    /**
     * A new secret for a platform's secret store, 32 random bytes in
     * base64: printed once by `key:generate --show`, kept nowhere.
     */
    public static function fresh(): string
    {
        return base64_encode(random_bytes(AppKey::BYTES));
    }
}
