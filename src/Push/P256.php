<?php

declare(strict_types=1);

namespace Tinvar\Push;

/**
 * The P-256 arithmetic web push needs, through OpenSSL: keys from their raw
 * bytes and back, Diffie-Hellman, and ES256 signatures in the raw r||s form
 * a JWT carries.
 */
final class P256
{
    /** An uncompressed point: 0x04, then x and y, 32 bytes each. */
    public const int POINT_BYTES = 65;

    /** A SubjectPublicKeyInfo for a P-256 key, up to the point it ends with. */
    private const string SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * The public key a raw uncompressed point is.
     *
     * OpenSSL refuses a point that is not on the curve, so a browser's
     * p256dh that is not one -- or one chosen to leak the server's key over
     * an invalid-curve exchange -- is refused here.
     *
     * @throws \InvalidArgumentException
     */
    public static function publicKey(string $point): \OpenSSLAsymmetricKey
    {
        if (\strlen($point) !== self::POINT_BYTES || $point[0] !== "\x04") {
            throw new \InvalidArgumentException('A public key is an uncompressed P-256 point: 65 bytes, the first 0x04.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode((string) hex2bin(self::SPKI_PREFIX) . $point), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \InvalidArgumentException('A public key is a point on P-256, and this is not one.');
        }

        return $key;
    }

    /**
     * The private key a raw 32-byte scalar is.
     *
     * @throws \InvalidArgumentException
     */
    public static function privateKey(#[\SensitiveParameter] string $scalar): \OpenSSLAsymmetricKey
    {
        if (\strlen($scalar) !== 32) {
            throw new \InvalidArgumentException('A private key is a P-256 scalar: 32 bytes.');
        }
        $key = @openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1', 'd' => $scalar]]);
        if ($key === false) {
            throw new \InvalidArgumentException('A private key is a P-256 scalar, and this is not one.');
        }

        return $key;
    }

    /** A fresh key pair. */
    public static function generate(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('OpenSSL made no P-256 key.');
        }

        return $key;
    }

    /** A key's public point, uncompressed. */
    public static function point(\OpenSSLAsymmetricKey $key): string
    {
        $ec = self::details($key);

        return "\x04" . self::pad($ec['x']) . self::pad($ec['y']);
    }

    /** A private key's scalar. */
    public static function scalar(\OpenSSLAsymmetricKey $key): string
    {
        $ec = self::details($key);
        if (!isset($ec['d'])) {
            throw new \InvalidArgumentException('This is a public key; it has no scalar.');
        }

        return self::pad($ec['d']);
    }

    /** The shared secret of ECDH between a private key and a public one. */
    public static function sharedSecret(\OpenSSLAsymmetricKey $private, \OpenSSLAsymmetricKey $public): string
    {
        $secret = openssl_pkey_derive($public, $private);
        if ($secret === false || \strlen($secret) !== 32) {
            throw new \RuntimeException('OpenSSL derived no shared secret.');
        }

        return $secret;
    }

    /** An ES256 signature over $data, as the 64 raw bytes r||s a JWS carries. */
    public static function sign(string $data, \OpenSSLAsymmetricKey $private): string
    {
        if (!openssl_sign($data, $der, $private, OPENSSL_ALGO_SHA256) || !\is_string($der)) {
            throw new \RuntimeException('OpenSSL did not sign.');
        }

        // SEQUENCE { INTEGER r, INTEGER s }, each a minimal two's
        // complement integer: a leading zero when the top bit is set, none
        // when the value is short.
        $offset = 2 + (\ord($der[1]) & 0x80 ? \ord($der[1]) & 0x7f : 0);
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            if (($der[$offset] ?? '') !== "\x02") {
                throw new \RuntimeException('OpenSSL signed in a form not understood.');
            }
            $length = \ord($der[$offset + 1]);
            $raw .= self::pad(ltrim(substr($der, $offset + 2, $length), "\x00"));
            $offset += 2 + $length;
        }

        return $raw;
    }

    /** @return array{x: string, y: string, d?: string} */
    private static function details(\OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        $ec = \is_array($details) ? ($details['ec'] ?? null) : null;
        if (!\is_array($ec) || ($ec['curve_name'] ?? null) !== 'prime256v1' || !\is_string($ec['x'] ?? null) || !\is_string($ec['y'] ?? null)) {
            throw new \InvalidArgumentException('This is not a P-256 key.');
        }
        $out = ['x' => $ec['x'], 'y' => $ec['y']];
        if (\is_string($ec['d'] ?? null)) {
            $out['d'] = $ec['d'];
        }

        return $out;
    }

    /** A big-endian number as 32 bytes: OpenSSL leaves out leading zeros. */
    private static function pad(string $bytes): string
    {
        if (\strlen($bytes) > 32) {
            throw new \RuntimeException('A P-256 coordinate is 32 bytes at most.');
        }

        return str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    }
}
