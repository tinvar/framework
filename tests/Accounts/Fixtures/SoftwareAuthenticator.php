<?php

declare(strict_types=1);

namespace Tinvar\Tests\Accounts\Fixtures;

use Tinvar\Accounts\Passkeys\CborBytes;

/**
 * An authenticator in software, for the passkey tests: a real ES256 or
 * RS256 key made with OpenSSL, and the registrations and sign-ins a browser
 * would hand back from it, each part changeable so a test can break exactly
 * one thing -- the origin, the relying party, the challenge, a flag, the
 * counter, the format, the signature.
 */
final class SoftwareAuthenticator
{
    public const string ORIGIN = 'https://app.example';
    public const string RP_ID = 'app.example';

    public string $credentialId;

    public int $signCount = 0;

    private function __construct(private readonly \OpenSSLAsymmetricKey $key, public readonly int $algorithm)
    {
        $this->credentialId = random_bytes(32);
    }

    public static function es256(): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert($key instanceof \OpenSSLAsymmetricKey);

        return new self($key, -7);
    }

    public static function rs256(int $bits = 2048): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        \assert($key instanceof \OpenSSLAsymmetricKey);

        return new self($key, -257);
    }

    /** @return array<int, mixed> the public key as a COSE_Key map */
    public function coseKey(): array
    {
        $details = openssl_pkey_get_details($this->key);
        \assert(\is_array($details));
        if ($this->algorithm === -7) {
            return [
                1 => 2,
                3 => -7,
                -1 => 1,
                -2 => new CborBytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
                -3 => new CborBytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            ];
        }

        return [1 => 3, 3 => -257, -1 => new CborBytes($details['rsa']['n']), -2 => new CborBytes($details['rsa']['e'])];
    }

    public function coseBytes(): string
    {
        return self::cbor($this->coseKey());
    }

    /**
     * A registration, as `navigator.credentials.create()` answers it.
     *
     * @param array{origin?: string, rpId?: string, type?: string, crossOrigin?: bool, flags?: int, signCount?: int, fmt?: string, attStmt?: array<mixed>, cose?: array<mixed>, trailing?: string} $o
     * @return array{clientDataJson: string, attestationObject: string, authData: string}
     */
    public function register(string $challenge, array $o = []): array
    {
        $client = $this->clientData($o['type'] ?? 'webauthn.create', $challenge, $o);
        $attested = hex2bin(str_repeat('ab', 16)) . pack('n', \strlen($this->credentialId)) . $this->credentialId
            . self::cbor($o['cose'] ?? $this->coseKey());
        $authData = hash('sha256', $o['rpId'] ?? self::RP_ID, true)
            . \chr($o['flags'] ?? 0x45) . pack('N', $o['signCount'] ?? 0) . $attested . ($o['trailing'] ?? '');

        $fmt = $o['fmt'] ?? 'none';
        $statement = $o['attStmt'] ?? match ($fmt) {
            'packed' => ['alg' => $this->algorithm, 'sig' => new CborBytes($this->sign($authData . hash('sha256', $client, true)))],
            default => [],
        };

        return [
            'clientDataJson' => $client,
            'attestationObject' => self::cbor(['fmt' => $fmt, 'attStmt' => $statement, 'authData' => new CborBytes($authData)]),
            'authData' => $authData,
        ];
    }

    /**
     * A sign-in, as `navigator.credentials.get()` answers it; the counter
     * moves on by one unless the options say what it is.
     *
     * @param array{origin?: string, rpId?: string, type?: string, crossOrigin?: bool, flags?: int, signCount?: int} $o
     * @return array{clientDataJson: string, authenticatorData: string, signature: string}
     */
    public function assert(string $challenge, array $o = []): array
    {
        $this->signCount = $o['signCount'] ?? $this->signCount + 1;
        $client = $this->clientData($o['type'] ?? 'webauthn.get', $challenge, $o);
        $authData = hash('sha256', $o['rpId'] ?? self::RP_ID, true) . \chr($o['flags'] ?? 0x05) . pack('N', $this->signCount);

        return [
            'clientDataJson' => $client,
            'authenticatorData' => $authData,
            'signature' => $this->sign($authData . hash('sha256', $client, true)),
        ];
    }

    public function sign(string $data): string
    {
        openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $signature;
    }

    /** @param array{origin?: string, crossOrigin?: bool} $o */
    private function clientData(string $type, string $challenge, array $o): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='),
            'origin' => $o['origin'] ?? self::ORIGIN,
            'crossOrigin' => $o['crossOrigin'] ?? false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** A tiny CBOR encoder: integers, text, CborBytes, lists, maps, booleans and null. */
    public static function cbor(mixed $value): string
    {
        return match (true) {
            \is_int($value) && $value >= 0 => self::head(0, $value),
            \is_int($value) => self::head(1, -1 - $value),
            $value instanceof CborBytes => self::head(2, \strlen($value->bytes)) . $value->bytes,
            \is_string($value) => self::head(3, \strlen($value)) . $value,
            \is_array($value) && array_is_list($value) && $value !== [] => self::head(4, \count($value)) . implode('', array_map(self::cbor(...), $value)),
            \is_array($value) => self::head(5, \count($value)) . implode('', array_map(
                static fn (int|string $k, mixed $v): string => self::cbor($k) . self::cbor($v),
                array_keys($value),
                $value,
            )),
            $value === false => "\xF4",
            $value === true => "\xF5",
            $value === null => "\xF6",
            default => throw new \InvalidArgumentException('Not encodable here.'),
        };
    }

    public static function head(int $major, int $argument): string
    {
        $type = $major << 5;

        return match (true) {
            $argument < 24 => \chr($type | $argument),
            $argument < 0x100 => \chr($type | 24) . \chr($argument),
            $argument < 0x10000 => \chr($type | 25) . pack('n', $argument),
            $argument < 0x100000000 => \chr($type | 26) . pack('N', $argument),
            default => \chr($type | 27) . pack('J', $argument),
        };
    }
}
