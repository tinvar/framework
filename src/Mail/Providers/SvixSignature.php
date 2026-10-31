<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

/**
 * The signature Svix puts on a webhook delivery, as Resend sends them:
 *
 *     svix-id: msg_2LgvL5BtK6aAvvGYmYCuzCvShVZ
 *     svix-timestamp: 1792836000
 *     svix-signature: v1,K5oZfzN95Z9UVu1EsfQmfVNQhnkZ2pj9o9NDN/H/pI4= v1,...
 *
 * An HMAC-SHA256, base64, over `{id}.{timestamp}.{body}`, keyed with the
 * secret's bytes -- the base64 after `whsec_`. The header may carry several
 * signatures, space-separated, while a secret is rotated: any `v1` that
 * matches is enough. The id is signed, so it is the delivery's nonce.
 */
final class SvixSignature
{
    /** What a message id may be. */
    public const string ID = '/^[A-Za-z0-9_-]{1,128}\z/';

    /** The secret's bytes, from `whsec_<base64>` or the base64 alone; null for neither. */
    public static function key(#[\SensitiveParameter] string $secret): ?string
    {
        $encoded = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $key = base64_decode($encoded, true);

        return $key === false || \strlen($key) < 16 ? null : $key;
    }

    /** The base64 signature for a delivery, as a sender makes it. */
    public static function sign(#[\SensitiveParameter] string $key, string $id, int $timestamp, string $body): string
    {
        return base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * Whether any `v1` signature in $header is $expected's.
     */
    public static function matches(?string $header, string $expected): bool
    {
        if ($header === null || \strlen($header) > 4096) {
            return false;
        }
        $found = false;
        foreach (preg_split('/\s+/', trim($header)) ?: [] as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');
            // Every entry compared, so how long it takes says nothing of which matched.
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                $found = true;
            }
        }

        return $found;
    }
}
