<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * An authenticator's data (WebAuthn L2 §6.1): the hash of the relying
 * party id it signed for, its flags, its signature counter, and -- in a
 * registration -- the new credential's id and public key.
 *
 *     rpIdHash (32) | flags (1) | signCount (4, big-endian)
 *     [ aaguid (16) | credentialIdLength (2) | credentialId | COSE key ]   when AT
 *     [ extensions (a CBOR map) ]                                          when ED
 *
 * Read exactly: the credential's key is one CBOR item, the extensions one
 * map, and a byte after either is refused, so nothing an authenticator
 * appends goes unread. The extensions are parsed for shape and otherwise
 * ignored: the kit asks for none.
 */
final readonly class AuthenticatorData
{
    public const int USER_PRESENT = 0x01;
    public const int USER_VERIFIED = 0x04;
    public const int BACKUP_ELIGIBLE = 0x08;
    public const int BACKED_UP = 0x10;
    public const int ATTESTED_CREDENTIAL = 0x40;
    public const int EXTENSIONS = 0x80;

    /** The shortest credential id WebAuthn allows a relying party to take, and the longest. */
    public const int MIN_CREDENTIAL_ID = 16;
    public const int MAX_CREDENTIAL_ID = 1023;

    /**
     * @param string $raw the bytes as they arrived, which a signature covers
     * @param string $rpIdHash SHA-256 of the relying party id, raw
     * @param string|null $aaguid the authenticator's model, raw 16 bytes, when AT
     * @param string|null $credentialId raw, when AT
     * @param string|null $credentialPublicKey the COSE key's bytes, when AT
     */
    private function __construct(
        public string $raw,
        public string $rpIdHash,
        public int $flags,
        public int $signCount,
        public ?string $aaguid,
        public ?string $credentialId,
        public ?string $credentialPublicKey,
    ) {}

    public static function parse(string $bytes): self
    {
        $length = \strlen($bytes);
        if ($length < 37) {
            throw PasskeyRefused::malformed();
        }

        $rpIdHash = substr($bytes, 0, 32);
        $flags = \ord($bytes[32]);
        $signCount = (\ord($bytes[33]) << 24) | (\ord($bytes[34]) << 16) | (\ord($bytes[35]) << 8) | \ord($bytes[36]);
        $offset = 37;

        $aaguid = $credentialId = $publicKey = null;
        if (($flags & self::ATTESTED_CREDENTIAL) !== 0) {
            if ($length < $offset + 18) {
                throw PasskeyRefused::malformed();
            }
            $aaguid = substr($bytes, $offset, 16);
            $idLength = (\ord($bytes[$offset + 16]) << 8) | \ord($bytes[$offset + 17]);
            $offset += 18;
            if ($idLength < self::MIN_CREDENTIAL_ID || $idLength > self::MAX_CREDENTIAL_ID || $length < $offset + $idLength) {
                throw PasskeyRefused::malformed();
            }
            $credentialId = substr($bytes, $offset, $idLength);
            $offset += $idLength;

            [$key, $end] = Cbor::decodeFirst($bytes, $offset);
            if (!\is_array($key)) {
                throw PasskeyRefused::malformed();
            }
            $publicKey = substr($bytes, $offset, $end - $offset);
            $offset = $end;
        }

        if (($flags & self::EXTENSIONS) !== 0) {
            [$extensions, $offset] = Cbor::decodeFirst($bytes, $offset);
            if (!\is_array($extensions)) {
                throw PasskeyRefused::malformed();
            }
        }

        if ($offset !== $length) {
            throw PasskeyRefused::malformed();
        }

        return new self($bytes, $rpIdHash, $flags, $signCount, $aaguid, $credentialId, $publicKey);
    }

    public function userPresent(): bool
    {
        return ($this->flags & self::USER_PRESENT) !== 0;
    }

    public function userVerified(): bool
    {
        return ($this->flags & self::USER_VERIFIED) !== 0;
    }

    public function backupEligible(): bool
    {
        return ($this->flags & self::BACKUP_ELIGIBLE) !== 0;
    }

    public function backedUp(): bool
    {
        return ($this->flags & self::BACKED_UP) !== 0;
    }

    public function hasAttestedCredential(): bool
    {
        return ($this->flags & self::ATTESTED_CREDENTIAL) !== 0;
    }

    public function hasExtensions(): bool
    {
        return ($this->flags & self::EXTENSIONS) !== 0;
    }
}
