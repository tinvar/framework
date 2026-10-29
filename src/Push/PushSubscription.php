<?php

declare(strict_types=1);

namespace Tinvar\Push;

use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\Target;
use Tinvar\Support\Signer;

/**
 * One browser's push subscription, held by one user in one tenant.
 *
 * What the browser's PushSubscription.toJSON() gives -- its endpoint, an
 * https URL at its push service, and its keys, p256dh and auth, base64url --
 * checked when it is made: the endpoint one OutboundHttp would send to and
 * https, the key a point on P-256, the secret 16 bytes. The endpoint is a
 * capability URL (whoever has it can push to that browser, given the
 * application's key), so a dump shows its origin alone.
 */
final readonly class PushSubscription
{
    /** The longest endpoint taken. */
    public const int MAX_ENDPOINT = 2048;

    /**
     * @throws \InvalidArgumentException for an endpoint or keys that are not a subscription's
     */
    public function __construct(
        public string $id,
        public string $tenant,
        public string $user,
        public string $endpoint,
        /** The browser's public key, base64url. */
        public string $p256dh,
        /** The secret the browser shares with the application alone, base64url. */
        public string $auth,
        public int $createdAt,
    ) {
        self::check($endpoint, $p256dh, $auth);
    }

    /**
     * The push service's origin, as a VAPID `aud` names it: scheme and
     * host, and a port only when it is not 443.
     */
    public function audience(): string
    {
        $target = Target::parse($this->endpoint);
        $host = $target->literal !== null && str_contains($target->host, ':') ? "[{$target->host}]" : $target->host;

        return "https://{$host}" . ($target->port === 443 ? '' : ":{$target->port}");
    }

    /** @throws \InvalidArgumentException */
    public static function check(string $endpoint, string $p256dh, string $auth): void
    {
        if (\strlen($endpoint) > self::MAX_ENDPOINT) {
            throw new \InvalidArgumentException('A push endpoint is at most ' . self::MAX_ENDPOINT . ' bytes.');
        }
        try {
            $target = Target::parse($endpoint);
        } catch (OutboundRefused $e) {
            throw new \InvalidArgumentException('A push endpoint is a URL a request can be sent to.', 0, $e);
        }
        if ($target->scheme !== 'https' || $target->serialized() !== $endpoint) {
            throw new \InvalidArgumentException('A push endpoint is an https URL, written as a browser writes it.');
        }

        $point = Signer::decode($p256dh);
        if ($point === null) {
            throw new \InvalidArgumentException('A subscription\'s p256dh is base64url.');
        }
        P256::publicKey($point);

        $secret = Signer::decode($auth);
        if ($secret === null || \strlen($secret) !== 16) {
            throw new \InvalidArgumentException('A subscription\'s auth is 16 bytes of base64url.');
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'tenant' => $this->tenant,
            'user' => $this->user,
            'endpoint' => Target::parse($this->endpoint)->origin() . '/[redacted]',
            'p256dh' => '[redacted]',
            'auth' => '[redacted]',
            'createdAt' => $this->createdAt,
        ];
    }
}
