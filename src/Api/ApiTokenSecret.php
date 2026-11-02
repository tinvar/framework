<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Http\Request;

/**
 * What an API token is as text: `tvr_<id>_<secret>`, and how it is read
 * from a request (ADR 0151).
 *
 *   - `tvr_` marks it as Tinvar's, so a secret scanner -- GitHub's, a
 *     pre-commit hook -- can find one pushed by mistake: SCANNER_PATTERN.
 *   - `<id>` is the stored token's id, 32 lowercase hex digits: what the
 *     token is looked up by. It is no secret.
 *   - `<secret>` is 43 characters of base62, about 256 bits from
 *     random_bytes(). Only its SHA-256 is stored, and a presented secret
 *     is compared with that digest in constant time (hash_equals), so
 *     neither the table nor how long a refusal takes gives it away.
 *
 * A token is read from `Authorization: Bearer <token>` alone: never a
 * cookie, a query string or a form field, which a browser sends or logs on
 * its own (RFC 6750 2.1). The scheme is matched in any case, the token
 * exactly.
 */
final class ApiTokenSecret
{
    /** What every token starts with. */
    public const string PREFIX = 'tvr_';

    /** The regular expression a secret scanner finds a token by. */
    public const string SCANNER_PATTERN = 'tvr_[0-9a-f]{32}_[0-9A-Za-z]{43}';

    /** How long the secret part is. */
    public const int SECRET_LENGTH = 43;

    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * A new token for the stored token $id: the text to hand its holder
     * once, and the digest to keep.
     *
     * @return array{string, string} the token, and the SHA-256 of its secret, in hex
     */
    public static function issue(string $id): array
    {
        if (preg_match('/^[0-9a-f]{32}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('An API token\'s id is 32 lowercase hex digits.');
        }
        $secret = self::randomSecret();

        return [self::PREFIX . $id . '_' . $secret, self::digest($secret)];
    }

    /**
     * The id and secret $token carries; null for anything not shaped as a
     * token is, which is refused before anything is looked up.
     *
     * @return array{string, string}|null
     */
    public static function parse(#[\SensitiveParameter] string $token): ?array
    {
        if (preg_match('/^' . self::SCANNER_PATTERN . '\z/', $token) !== 1) {
            return null;
        }

        return [substr($token, 4, 32), substr($token, 37)];
    }

    /** The digest a secret is kept as: SHA-256, in hex. */
    public static function digest(#[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * The bearer token a request carries in Authorization, whatever it is;
     * null for none. Only a request that names the Bearer scheme carries
     * one: anything else in the header is some other credential.
     */
    public static function bearerOf(Request $request): ?string
    {
        $header = $request->header('authorization');
        if ($header === null || preg_match('/^Bearer +([\x21-\x7E]+) *\z/i', $header, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** Whether a request presents an API token of Tinvar's, by its prefix: what a session's path refuses. */
    public static function presented(Request $request): bool
    {
        return str_starts_with(self::bearerOf($request) ?? '', self::PREFIX);
    }

    /** 43 characters of base62, each drawn without bias. */
    private static function randomSecret(): string
    {
        $secret = '';
        while (\strlen($secret) < self::SECRET_LENGTH) {
            foreach (str_split(random_bytes(64)) as $byte) {
                // 62 * 4 = 248: a byte at or past it would favour the first
                // characters, so it is drawn again.
                $value = \ord($byte);
                if ($value < 248) {
                    $secret .= self::ALPHABET[$value % 62];
                    if (\strlen($secret) === self::SECRET_LENGTH) {
                        break;
                    }
                }
            }
        }

        return $secret;
    }
}
