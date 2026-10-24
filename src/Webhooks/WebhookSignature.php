<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * The signature a webhook delivery carries, in one header:
 *
 *     Tinvar-Signature: t=1792836000,n=4f9c2a1e8b7d6c5a,v1=<hex HMAC-SHA256>
 *
 * The MAC covers the timestamp, the nonce, the method, the path and query
 * the delivery was sent to, and the body, so none can change without it
 * failing: a body cannot be replayed under a new timestamp, nor a delivery
 * for one tenant's URL sent to another's.
 */
final class WebhookSignature
{
    public const string HEADER = 'tinvar-signature';

    /** A nonce: what the sender chooses to make each delivery its own. */
    public const string NONCE_SHAPE = '/^[A-Za-z0-9_-]{16,128}\z/';

    /** The header value for a delivery, as a sender computes it. */
    public static function header(string $secret, int $timestamp, string $nonce, string $method, string $target, string $body): string
    {
        return "t={$timestamp},n={$nonce},v1=" . self::mac($secret, $timestamp, $nonce, $method, $target, $body);
    }

    /**
     * The header's timestamp, nonce and MAC, or null when it is not one.
     *
     * @return array{int, string, string}|null
     */
    public static function parse(?string $header): ?array
    {
        if ($header === null || preg_match('/^t=([1-9]\d{0,11}),n=([A-Za-z0-9_-]{16,128}),v1=([0-9a-f]{64})\z/', trim($header), $m) !== 1) {
            return null;
        }

        return [(int) $m[1], $m[2], $m[3]];
    }

    public static function mac(string $secret, int $timestamp, string $nonce, string $method, string $target, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$nonce}." . strtoupper($method) . " {$target}\n{$body}", $secret);
    }
}
