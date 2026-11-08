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
 * While a sender rotates its secret (ADR 0197) a delivery carries one MAC
 * for each secret it holds, the new one first, so a receiver still holding
 * the old secret verifies it as surely as one given the new:
 *
 *     Tinvar-Signature: t=1792836000,n=4f9c2a1e8b7d6c5a,v1=<new>,v1=<old>
 *
 * A receiver accepts the delivery when any one of them verifies.
 */
final class WebhookSignature
{
    public const string HEADER = 'tinvar-signature';

    /** A nonce: what the sender chooses to make each delivery its own. */
    public const string NONCE_SHAPE = '/^[A-Za-z0-9_-]{16,128}\z/';

    /** The most MACs one header carries: a secret and the one it replaces. */
    public const int MAX_MACS = 2;

    /** The header value for a delivery, as a sender computes it. */
    public static function header(#[\SensitiveParameter] string $secret, int $timestamp, string $nonce, string $method, string $target, string $body): string
    {
        return self::headerForEach([$secret], $timestamp, $nonce, $method, $target, $body);
    }

    /**
     * The header value for a delivery signed with each of $secrets, in
     * order: the secret in force, then the one it replaced while both sign
     * (ADR 0197).
     *
     * @param list<string> $secrets
     */
    public static function headerForEach(#[\SensitiveParameter] array $secrets, int $timestamp, string $nonce, string $method, string $target, string $body): string
    {
        if ($secrets === [] || \count($secrets) > self::MAX_MACS) {
            throw new \InvalidArgumentException('A delivery is signed with one secret, or two while one replaces the other.');
        }

        $header = "t={$timestamp},n={$nonce}";
        foreach ($secrets as $secret) {
            $header .= ',v1=' . self::mac($secret, $timestamp, $nonce, $method, $target, $body);
        }

        return $header;
    }

    /**
     * The header's timestamp, nonce and first MAC, or null when it is not one.
     *
     * @return array{int, string, string}|null
     */
    public static function parse(?string $header): ?array
    {
        $parsed = self::parseEach($header);

        return $parsed === null ? null : [$parsed[0], $parsed[1], $parsed[2][0]];
    }

    /**
     * The header's timestamp, nonce and every MAC it carries -- one, or two
     * while the sender rotates its secret -- or null when it is not one.
     *
     * @return array{int, string, non-empty-list<string>}|null
     */
    public static function parseEach(?string $header): ?array
    {
        if ($header === null || preg_match('/^t=([1-9]\d{0,11}),n=([A-Za-z0-9_-]{16,128})((?:,v1=[0-9a-f]{64}){1,' . self::MAX_MACS . '})\z/', trim($header), $m) !== 1) {
            return null;
        }
        $macs = array_values(array_filter(explode(',v1=', $m[3]), static fn (string $mac): bool => $mac !== ''));

        return $macs === [] ? null : [(int) $m[1], $m[2], $macs];
    }

    public static function mac(#[\SensitiveParameter] string $secret, int $timestamp, string $nonce, string $method, string $target, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$nonce}." . strtoupper($method) . " {$target}\n{$body}", $secret);
    }
}
