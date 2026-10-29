<?php

declare(strict_types=1);

namespace Tinvar\Push;

use Tinvar\Support\Signer;

/**
 * The application's VAPID key pair (RFC 8292), and the Authorization
 * header it signs for a push service.
 *
 * Each header is a JWT, ES256, for one push service's origin (`aud`), for
 * twelve hours at most (`exp`; RFC 8292 allows a day), naming the
 * application's contact (`sub`), sent with the public key (`k`) the
 * browser's subscription was made with -- so a push service delivers only
 * what this key signed to a subscription made for it.
 *
 * The private key is an OpenSSL key object, never a property a dump could
 * print: __debugInfo() shows the public half alone, and it is never
 * serialized.
 */
final readonly class VapidKeys
{
    /** Seconds a header is good for. */
    public const int LIFETIME = 43_200;

    private function __construct(
        /** The public key, base64url, as the browser's pushManager.subscribe() is handed it. */
        public string $publicKey,
        private \OpenSSLAsymmetricKey $private,
        public string $subject,
    ) {}

    /** @throws \InvalidArgumentException for keys that are not a P-256 pair */
    public static function fromConfig(VapidConfig $config): self
    {
        return self::fromStrings($config->publicKey, $config->privateKey->reveal(), $config->subject);
    }

    /** @throws \InvalidArgumentException for keys that are not a P-256 pair */
    public static function fromStrings(string $publicKey, #[\SensitiveParameter] string $privateKey, string $subject): self
    {
        $point = Signer::decode($publicKey);
        if ($point === null) {
            throw new \InvalidArgumentException('The public key is not base64url.');
        }
        P256::publicKey($point);

        $scalar = Signer::decode($privateKey);
        if ($scalar === null || \strlen($scalar) !== 32) {
            throw new \InvalidArgumentException('The private key is not 32 bytes of base64url.');
        }
        $private = P256::privateKey($scalar);
        if (!hash_equals(P256::point($private), $point)) {
            throw new \InvalidArgumentException('The private key is not the public key\'s.');
        }

        return new self(Signer::encode($point), $private, $subject);
    }

    /**
     * A new pair, base64url, for TINVAR_VAPID_PUBLIC_KEY and
     * TINVAR_VAPID_PRIVATE_KEY.
     *
     * @return array{public: string, private: string}
     */
    public static function generate(): array
    {
        $key = P256::generate();

        return ['public' => Signer::encode(P256::point($key)), 'private' => Signer::encode(P256::scalar($key))];
    }

    /**
     * The Authorization header for a push to $endpoint's push service.
     *
     * @param string $audience the push service's origin: scheme, host and a port only when not the default
     */
    public function authorization(string $audience, int $now): string
    {
        $header = Signer::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = Signer::encode((string) json_encode(['aud' => $audience, 'exp' => $now + self::LIFETIME, 'sub' => $this->subject], JSON_UNESCAPED_SLASHES));
        $signature = Signer::encode(P256::sign("{$header}.{$claims}", $this->private));

        return "vapid t={$header}.{$claims}.{$signature}, k={$this->publicKey}";
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['publicKey' => $this->publicKey, 'privateKey' => '[redacted]', 'subject' => $this->subject];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('VAPID keys are never serialized.');
    }
}
