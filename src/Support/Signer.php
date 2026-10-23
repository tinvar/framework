<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * Keyed MACs over framework-issued strings.
 *
 * tinvar-spec.md, "Deferred fragment tokens": "The signing key is derived from
 * `APP_KEY` for this one purpose."
 *
 * Derived, not used directly, so a token signature can never be confused with
 * anything else the key protects -- a session cookie, an upload ticket, a
 * signed URL. Each purpose gets its own key from one secret, so a value signed
 * for one can never verify against another even though the bytes and the
 * algorithm are identical.
 */
final readonly class Signer
{
    private const string ALGORITHM = 'sha256';

    private string $key;

    /**
     * @param string $secret  the application key
     * @param string $purpose what this signer is for, e.g. 'fragment-token'
     */
    public function __construct(string $secret, string $purpose)
    {
        if ($secret === '') {
            // A signer with no key would produce stable, forgeable MACs that
            // look exactly like real ones. Refuse rather than sign with ''.
            throw new \InvalidArgumentException('A signing secret is required; set APP_KEY.');
        }

        if ($purpose === '') {
            throw new \InvalidArgumentException('A signing purpose is required; it is what separates one key from another.');
        }

        $this->key = hash_hmac(self::ALGORITHM, $purpose, $secret, binary: true);
    }

    /** Raw MAC over a payload. */
    public function sign(string $payload): string
    {
        return hash_hmac(self::ALGORITHM, $payload, $this->key, binary: true);
    }

    /**
     * Whether a MAC is the one this signer would produce.
     *
     * hash_equals, not ===: a timing difference here leaks the signature a
     * byte at a time, which is enough to forge one.
     */
    public function verify(string $payload, string $signature): bool
    {
        return hash_equals($this->sign($payload), $signature);
    }

    /** URL-safe base64 without padding, for values that travel in a path. */
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** The inverse. Returns null for anything that is not valid encoding. */
    public static function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $raw = base64_decode(strtr($encoded, '-_', '+/'), strict: true);

        return $raw === false ? null : $raw;
    }
}
