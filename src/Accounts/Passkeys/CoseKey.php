<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * A credential's public key as an authenticator writes it -- a COSE_Key
 * (RFC 9052, 9053) -- held to the two algorithms the kit asks for:
 *
 * - ES256: ECDSA on P-256 with SHA-256 (kty 2, alg -7, crv 1), its point as
 *   32-byte x and y. OpenSSL refuses a point that is not on the curve when
 *   the key is loaded, which happens here, at construction: a key that
 *   would never verify is never stored.
 * - RS256: RSASSA-PKCS1-v1_5 with SHA-256 (kty 3, alg -257), a modulus of
 *   2048 bits or more.
 *
 * Everything else -- EdDSA, ES384, an RSA key of 1024 bits, a key whose
 * declared algorithm does not match its type -- is refused, so what is
 * registered is a key the server knows how to check. The key is turned into
 * a SubjectPublicKeyInfo built here, byte by byte, for OpenSSL to verify
 * with; no library parses the authenticator's bytes.
 */
final readonly class CoseKey
{
    public const int ES256 = -7;

    public const int RS256 = -257;

    private const int KTY = 1;
    private const int ALG = 3;
    private const int EC2 = 2;
    private const int RSA = 3;
    private const int P256 = 1;

    /** The smallest RSA modulus accepted, in bits. */
    public const int MIN_RSA_BITS = 2048;

    /** The SubjectPublicKeyInfo of a P-256 key, up to the uncompressed point's bytes. */
    private const string EC_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** AlgorithmIdentifier of rsaEncryption (1.2.840.113549.1.1.1) with NULL parameters. */
    private const string RSA_ALGORITHM = '300d06092a864886f70d0101010500';

    private function __construct(
        public int $algorithm,
        private string $pem,
    ) {
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new PasskeyRefused('algorithm', 'The passkey\'s public key could not be read. Try again with another passkey or security key.');
        }
    }

    /** The key a COSE_Key's bytes describe; the bytes must be the one map and nothing after it. */
    public static function fromBytes(string $coseBytes): self
    {
        $map = Cbor::decode($coseBytes);
        if (!\is_array($map)) {
            throw PasskeyRefused::malformed();
        }

        return self::fromCbor($map);
    }

    /** @param array<mixed> $map a decoded COSE_Key */
    public static function fromCbor(array $map): self
    {
        $type = $map[self::KTY] ?? null;
        $algorithm = $map[self::ALG] ?? null;

        if ($type === self::EC2 && $algorithm === self::ES256) {
            $curve = $map[-1] ?? null;
            $x = $map[-2] ?? null;
            $y = $map[-3] ?? null;
            if ($curve !== self::P256 || !$x instanceof CborBytes || !$y instanceof CborBytes
                || \strlen($x->bytes) !== 32 || \strlen($y->bytes) !== 32) {
                throw self::unsupported();
            }
            $der = hex2bin(self::EC_P256_PREFIX) . "\x04" . $x->bytes . $y->bytes;

            return new self(self::ES256, self::pem($der));
        }

        if ($type === self::RSA && $algorithm === self::RS256) {
            $n = $map[-1] ?? null;
            $e = $map[-2] ?? null;
            if (!$n instanceof CborBytes || !$e instanceof CborBytes) {
                throw self::unsupported();
            }
            $modulus = ltrim($n->bytes, "\0");
            $exponent = ltrim($e->bytes, "\0");
            if (\strlen($modulus) * 8 < self::MIN_RSA_BITS || $exponent === '' || \strlen($exponent) > 8) {
                throw self::unsupported();
            }
            $publicKey = self::sequence(self::integer($modulus) . self::integer($exponent));
            $der = self::sequence(hex2bin(self::RSA_ALGORITHM) . self::tlv(0x03, "\0" . $publicKey));

            return new self(self::RS256, self::pem($der));
        }

        throw self::unsupported();
    }

    /** The key as PEM, SubjectPublicKeyInfo, for OpenSSL. */
    public function toPem(): string
    {
        return $this->pem;
    }

    /**
     * Whether $signature is this key's over $data, by its algorithm: an
     * ES256 signature in ASN.1 DER, as WebAuthn sends it, or an RS256 one.
     */
    public function verify(string $data, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }
        $key = openssl_pkey_get_public($this->pem);
        if ($key === false) {
            return false;
        }

        // 1 is a good signature; 0 a bad one, -1 an error -- a malformed DER
        // signature among them -- and both are refusals.
        return openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function unsupported(): PasskeyRefused
    {
        return new PasskeyRefused('algorithm', 'This passkey uses a kind of key the app does not accept. Try another passkey or security key.');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** A DER INTEGER of a non-negative big-endian number with no leading zero bytes. */
    private static function integer(string $magnitude): string
    {
        // A set top bit would read as negative: a zero byte in front keeps it positive.
        if ((\ord($magnitude[0]) & 0x80) !== 0) {
            $magnitude = "\0" . $magnitude;
        }

        return self::tlv(0x02, $magnitude);
    }

    private static function sequence(string $content): string
    {
        return self::tlv(0x30, $content);
    }

    private static function tlv(int $tag, string $content): string
    {
        $length = \strlen($content);
        if ($length < 0x80) {
            return pack('CC', $tag, $length) . $content;
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return pack('CC', $tag, 0x80 | \strlen($bytes)) . $bytes . $content;
    }
}
