<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * Base64url without padding (RFC 4648 §5), as WebAuthn writes challenges,
 * credential ids and user handles.
 *
 * Decoding is strict: only the url-safe alphabet, no padding, no length a
 * real encoding never has, and the spare low bits of the last character
 * zero -- checked by encoding what was decoded and comparing. So every
 * value has one spelling, and a credential id or a challenge cannot be
 * re-spelled into a second string that means the same bytes.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** The bytes $text encodes, or null when it is not one canonical base64url spelling. */
    public static function decode(string $text): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*\z/', $text) !== 1 || \strlen($text) % 4 === 1) {
            return null;
        }

        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false || self::encode($bytes) !== $text) {
            return null;
        }

        return $bytes;
    }
}
