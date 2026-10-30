<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Support\Signer;

/**
 * A provider's public JWK (RFC 7517) as a key OpenSSL verifies with, for
 * the algorithms an ID token may be signed with here (IdTokenVerifier).
 *
 * Built from the key's own numbers into a SubjectPublicKeyInfo, never from
 * anything that could hold a private or a symmetric part: OidcDiscovery
 * drops such keys before they get here, and this reads only `n` and `e`,
 * or `crv`, `x` and `y`. An RSA modulus is 2048 bits at least; an EC point
 * is on the curve its algorithm names, which OpenSSL checks as it loads it.
 * An OKP key (RFC 8037) is Ed25519 alone, its `x` the 32 bytes of the
 * point: Ed448 is not taken, and an X25519 key, which agrees keys rather
 * than signing, is not one of these.
 */
final class Jwk
{
    /** The fewest bits an RSA key may have. */
    public const int MIN_RSA_BITS = 2048;

    /** Each EC curve: its DER OID and its coordinate length. */
    private const array CURVES = [
        'P-256' => ["\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07", 32],
        'P-384' => ["\x06\x05\x2b\x81\x04\x00\x22", 48],
        'P-521' => ["\x06\x05\x2b\x81\x04\x00\x23", 66],
    ];

    private const string RSA_OID = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
    private const string EC_OID = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
    private const string ED25519_OID = "\x06\x03\x2b\x65\x70";

    /**
     * The key's PEM, for an algorithm of $kty, on $curve for EC.
     *
     * @param array<string, mixed> $jwk
     * @throws IdTokenRefused for a key that is not one of these
     */
    public static function publicKey(array $jwk, string $kty, ?string $curve = null): \OpenSSLAsymmetricKey
    {
        if (($jwk['kty'] ?? null) !== $kty) {
            throw new IdTokenRefused(IdTokenRefused::KEY, "The key is not an {$kty} key.");
        }

        $der = match ($kty) {
            'RSA' => self::rsa($jwk),
            'OKP' => self::okp($jwk, (string) $curve),
            default => self::ec($jwk, (string) $curve),
        };
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new IdTokenRefused(IdTokenRefused::KEY, 'The key does not load as a public key.');
        }

        return $key;
    }

    /** @param array<string, mixed> $jwk */
    private static function rsa(array $jwk): string
    {
        $n = self::bytes($jwk, 'n');
        $e = self::bytes($jwk, 'e');
        $n = ltrim($n, "\0");
        if (\strlen($n) * 8 < self::MIN_RSA_BITS || \strlen($e) > 8 || ltrim($e, "\0") === '') {
            throw new IdTokenRefused(IdTokenRefused::KEY, 'The RSA key is shorter than ' . self::MIN_RSA_BITS . ' bits, or its exponent is not one.');
        }

        $key = self::der(0x30, self::integer($n) . self::integer($e));

        return self::der(0x30, self::der(0x30, self::RSA_OID . "\x05\x00") . self::der(0x03, "\x00" . $key));
    }

    /** @param array<string, mixed> $jwk */
    private static function ec(array $jwk, string $curve): string
    {
        if (($jwk['crv'] ?? null) !== $curve || !isset(self::CURVES[$curve])) {
            throw new IdTokenRefused(IdTokenRefused::KEY, "The EC key is not on {$curve}.");
        }
        [$oid, $length] = self::CURVES[$curve];
        $x = self::bytes($jwk, 'x');
        $y = self::bytes($jwk, 'y');
        if (\strlen($x) !== $length || \strlen($y) !== $length) {
            throw new IdTokenRefused(IdTokenRefused::KEY, "The EC key's point is not {$curve}'s size.");
        }

        return self::der(0x30, self::der(0x30, self::EC_OID . $oid) . self::der(0x03, "\x00\x04" . $x . $y));
    }

    /** @param array<string, mixed> $jwk */
    private static function okp(array $jwk, string $curve): string
    {
        if ($curve !== 'Ed25519' || ($jwk['crv'] ?? null) !== 'Ed25519') {
            throw new IdTokenRefused(IdTokenRefused::KEY, 'The OKP key is not an Ed25519 key.');
        }
        $x = self::bytes($jwk, 'x');
        if (\strlen($x) !== 32) {
            throw new IdTokenRefused(IdTokenRefused::KEY, "The Ed25519 key's point is not 32 bytes.");
        }

        return self::der(0x30, self::der(0x30, self::ED25519_OID) . self::der(0x03, "\x00" . $x));
    }

    /**
     * A JWS ECDSA signature -- r and s side by side, each the curve's size
     * (RFC 7518, 3.4) -- as the DER sequence OpenSSL verifies.
     */
    public static function ecdsaSignature(string $raw, string $curve): ?string
    {
        $length = self::CURVES[$curve][1] ?? 0;
        if ($length === 0 || \strlen($raw) !== 2 * $length) {
            return null;
        }

        return self::der(0x30, self::integer(substr($raw, 0, $length)) . self::integer(substr($raw, $length)));
    }

    /** @param array<string, mixed> $jwk */
    private static function bytes(array $jwk, string $member): string
    {
        $value = $jwk[$member] ?? null;
        $bytes = \is_string($value) ? Signer::decode($value) : null;
        if ($bytes === null || $bytes === '') {
            throw new IdTokenRefused(IdTokenRefused::KEY, "The key's {$member} is missing or not base64url.");
        }

        return $bytes;
    }

    /** An unsigned big-endian number as a DER INTEGER. */
    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || \ord($bytes[0]) >= 0x80) {
            $bytes = "\0" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    /** @param int<0, 255> $tag */
    private static function der(int $tag, string $content): string
    {
        $length = \strlen($content);
        if ($length < 0x80) {
            return pack('CC', $tag, $length) . $content;
        }
        $encoded = ltrim(pack('N', $length), "\0");

        return pack('CC', $tag, 0x80 | \strlen($encoded)) . $encoded . $content;
    }
}
