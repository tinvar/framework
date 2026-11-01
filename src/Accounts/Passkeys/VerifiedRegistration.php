<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * A registration WebAuthn verified: the credential to store.
 *
 * @param string $credentialId raw bytes
 * @param string $publicKey the COSE key's bytes, as stored and later verified with
 * @param string $aaguid the authenticator's model, raw 16 bytes; zeros under `none`
 * @param string $format the attestation format it came with: `none` or `packed`
 */
final readonly class VerifiedRegistration
{
    public function __construct(
        public string $credentialId,
        public string $publicKey,
        public int $algorithm,
        public int $signCount,
        public string $aaguid,
        public bool $userVerified,
        public bool $backupEligible,
        public bool $backedUp,
        public string $format,
    ) {}
}
