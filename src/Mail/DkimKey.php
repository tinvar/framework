<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Config\Secret;

/**
 * The private key a DkimSigner signs with: RSA-SHA256 (RFC 6376) or
 * Ed25519-SHA256 (RFC 8463), read from a Secret -- DkimConfig's, which the
 * environment or a mounted file gives -- and kept in this process alone
 * (ADR 0116). The renderer never holds it: `tinvar serve` hands it an
 * allowlisted environment, and it refuses to start holding any
 * `*_PRIVATE_KEY` or `*_PRIVATE_KEY_FILE`.
 *
 * Given as PEM -- an RSA key, PKCS#1 or PKCS#8, or an Ed25519 one in PKCS#8,
 * as `openssl genpkey -algorithm ed25519` writes it -- or, for Ed25519, as
 * RFC 8463's base64 of the 32-byte seed. An RSA key under 2048 bits is
 * refused unless the caller says otherwise: RFC 8301 allows 1024, and a
 * 1024-bit key is one a well-funded attacker can factor.
 *
 * Nothing about it prints: the Ed25519 seed stays a Secret, an RSA key an
 * OpenSSL handle, it dumps as `[redacted]`, and it refuses serialization.
 */
final class DkimKey
{
    public const string RSA = 'rsa-sha256';

    public const string ED25519 = 'ed25519-sha256';

    public const int MIN_RSA_BITS = 2048;

    private function __construct(
        public readonly string $algorithm,
        private readonly ?\OpenSSLAsymmetricKey $rsa,
        private readonly ?Secret $ed25519,
    ) {}

    /** @throws \InvalidArgumentException naming what is wrong with it, never quoting it */
    public static function fromSecret(#[\SensitiveParameter] Secret $key, int $minimumRsaBits = self::MIN_RSA_BITS): self
    {
        return self::parse(trim($key->reveal()), $minimumRsaBits);
    }

    /** @throws \InvalidArgumentException */
    public static function parse(#[\SensitiveParameter] string $key, int $minimumRsaBits = self::MIN_RSA_BITS): self
    {
        if (str_starts_with($key, '-----BEGIN ')) {
            $handle = @openssl_pkey_get_private($key);
            $details = $handle === false ? false : openssl_pkey_get_details($handle);
            if ($handle === false || $details === false) {
                throw new \InvalidArgumentException('The DKIM key is PEM that OpenSSL cannot read as a private key.');
            }
            if ($details['type'] === OPENSSL_KEYTYPE_RSA) {
                if ($details['bits'] < $minimumRsaBits) {
                    throw new \InvalidArgumentException("The DKIM key is RSA of {$details['bits']} bits; it must be at least {$minimumRsaBits}.");
                }

                return new self(self::RSA, $handle, null);
            }
            $seed = $details['ed25519']['priv_key'] ?? null;
            if ($details['type'] === OPENSSL_KEYTYPE_ED25519 && \is_string($seed) && \strlen($seed) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                return new self(self::ED25519, null, new Secret($seed));
            }

            throw new \InvalidArgumentException('The DKIM key is neither RSA nor Ed25519.');
        }

        $seed = base64_decode($key, true);
        if (!\is_string($seed) || \strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \InvalidArgumentException('The DKIM key is PEM, or the base64 of a 32-byte Ed25519 seed.');
        }

        return new self(self::ED25519, null, new Secret($seed));
    }

    /**
     * The signature of $data, the canonical headers RFC 6376 has signed: an
     * RSA PKCS#1 v1.5 signature of its SHA-256, or -- RFC 8463 -- PureEdDSA
     * Ed25519 over its SHA-256 hash.
     */
    public function sign(string $data): string
    {
        if ($this->rsa !== null) {
            if (!openssl_sign($data, $signature, $this->rsa, OPENSSL_ALGO_SHA256) || !\is_string($signature)) {
                throw new MailTransportException('The DKIM key could not sign the message.');
            }

            return $signature;
        }

        $pair = sodium_crypto_sign_seed_keypair($this->seed());
        try {
            return sodium_crypto_sign_detached(hash('sha256', $data, true), sodium_crypto_sign_secretkey($pair));
        } finally {
            sodium_memzero($pair);
        }
    }

    /** The public key, base64, as the selector's `p=` record publishes it. */
    public function publicKey(): string
    {
        if ($this->rsa !== null) {
            $details = openssl_pkey_get_details($this->rsa);
            $pem = \is_array($details) && \is_string($details['key'] ?? null) ? $details['key'] : '';

            return (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);
        }

        $pair = sodium_crypto_sign_seed_keypair($this->seed());

        return base64_encode(sodium_crypto_sign_publickey($pair));
    }

    /** @return non-empty-string the Ed25519 seed */
    private function seed(): string
    {
        $seed = $this->ed25519?->reveal() ?? '';
        if ($seed === '') {
            throw new \LogicException('This DKIM key is not Ed25519.');
        }

        return $seed;
    }

    /** The `k=` of its record: rsa or ed25519. */
    public function keyType(): string
    {
        return $this->algorithm === self::RSA ? 'rsa' : 'ed25519';
    }

    /** @return array{algorithm: string, key: string} */
    public function __debugInfo(): array
    {
        return ['algorithm' => $this->algorithm, 'key' => Secret::REDACTED];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A DKIM key is never serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     * @return never
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A DKIM key is never unserialized.');
    }
}
