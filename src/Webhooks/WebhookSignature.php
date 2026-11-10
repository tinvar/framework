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
 * for one tenant's URL sent to another's. Not the host: a tenant placed by
 * its host is bound by its own secret, or -- where tenants share one -- by
 * the endpoint claiming each nonce for everyone who holds it.
 *
 * While a sender rotates its secret (ADR 0197), this header stays exactly
 * as it always was -- one MAC -- made with the secret being replaced, so a
 * receiver of any version still holding it verifies every delivery. The
 * new secret's MAC goes beside it, in a header of its own and the same
 * format (NEXT_HEADER), which WebhookEndpoint also accepts: a receiver
 * may take the new secret up at any time inside the overlap, and one that
 * reads only this header must have it by the time the overlap ends.
 *
 * HEADER and NEXT_HEADER are the default names. An application may name
 * its own (TINVAR_WEBHOOK_SIGNATURE_HEADER, ADR 0200), which the sender
 * and the endpoint read from WebhookSignatureHeader in their place; the
 * value and the MAC are the same under any name.
 */
final class WebhookSignature
{
    /** The signature's header unless the application names another (Tinvar\Config\WebhookSignatureHeader). */
    public const string HEADER = 'tinvar-signature';

    /** The new secret's signature while a rotation's overlap lasts (ADR 0197): this format, its own header. */
    public const string NEXT_HEADER = 'tinvar-signature-next';

    /** A nonce: what the sender chooses to make each delivery its own. */
    public const string NONCE_SHAPE = '/^[A-Za-z0-9_-]{16,128}\z/';

    /** The header value for a delivery, as a sender computes it. */
    public static function header(#[\SensitiveParameter] string $secret, int $timestamp, string $nonce, string $method, string $target, string $body): string
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

    public static function mac(#[\SensitiveParameter] string $secret, int $timestamp, string $nonce, string $method, string $target, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$nonce}." . strtoupper($method) . " {$target}\n{$body}", $secret);
    }
}
