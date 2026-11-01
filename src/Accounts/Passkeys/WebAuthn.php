<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * Verifies what a browser sends back from a passkey ceremony -- a new
 * credential (WebAuthn Level 2, §7.1) or a sign-in with one (§7.2) --
 * against the relying party and the challenge the server issued.
 *
 * Implemented here, ES256 and RS256 alone, rather than through a WebAuthn
 * library: the packages that do it bring a dozen dependencies of their own
 * into an allowlisted tree (ADR 0040), and what a relying party that asks
 * for no attestation must check is short. Every check below is from the
 * specification, in its order, and every failure is a PasskeyRefused with
 * nothing changed:
 *
 * - the client data: its type is this ceremony's, its challenge is the one
 *   issued (compared in constant time), its origin one the relying party
 *   names, and it did not run in a cross-origin frame;
 * - the authenticator's data: signed for this relying party's id (the
 *   SHA-256 of the id, compared in constant time), the person present, and
 *   -- where asked, which the kit always does -- verified by the
 *   authenticator: a PIN, a fingerprint, a face;
 * - for a registration, the new credential's key, ES256 or RS256, and an
 *   attestation of the format `none` -- what a browser sends when, as here,
 *   none is asked for -- or `packed`, whose signature is verified;
 * - for a sign-in, the signature over the authenticator's data and the
 *   client data's hash, by the key stored at registration, and the
 *   signature counter, which must move forward wherever either side has
 *   one: a counter that stands still or goes back is a second copy of the
 *   authenticator, and refused.
 *
 * What this does not do is find the credential: the caller looks it up by
 * the id the browser sent, holds it to the account it is signing in -- a
 * discoverable credential's user handle, compared with the one stored --
 * and hands its key and counter in. Nor does it keep challenges: the caller
 * issues each one, binds it to the session, and hands it in once.
 */
final readonly class WebAuthn
{
    public const string CREATE = 'webauthn.create';

    public const string GET = 'webauthn.get';

    public function __construct(private RelyingParty $rp) {}

    /**
     * A registration: the client data's JSON and the attestation object, as
     * the browser's `PublicKeyCredential.response` holds them, decoded from
     * base64url; and the challenge the server issued for it, raw.
     *
     * A `packed` attestation with a certificate (x5c) has its signature
     * verified with the certificate's key, and that certificate's chain is
     * not: the kit asks for no attestation and trusts no authenticator
     * vendor's, so the certificate proves nothing it relies on, and the
     * signature is checked only so that an attestation that does not even
     * verify is not stored.
     */
    public function verifyRegistration(string $clientDataJson, string $attestationObject, string $expectedChallenge, bool $requireUserVerification = true): VerifiedRegistration
    {
        $this->checkClientData(ClientData::parse($clientDataJson), self::CREATE, $expectedChallenge);

        $attestation = Cbor::decode($attestationObject);
        if (!\is_array($attestation)) {
            throw PasskeyRefused::malformed();
        }
        $format = $attestation['fmt'] ?? null;
        $statement = $attestation['attStmt'] ?? null;
        $authDataBytes = $attestation['authData'] ?? null;
        if (!\is_string($format) || !\is_array($statement) || !$authDataBytes instanceof CborBytes) {
            throw PasskeyRefused::malformed();
        }

        $authData = AuthenticatorData::parse($authDataBytes->bytes);
        $this->checkAuthenticatorData($authData, $requireUserVerification);
        if (!$authData->hasAttestedCredential() || $authData->credentialId === null || $authData->credentialPublicKey === null || $authData->aaguid === null) {
            throw PasskeyRefused::malformed();
        }

        $key = CoseKey::fromBytes($authData->credentialPublicKey);
        $signed = $authData->raw . hash('sha256', $clientDataJson, true);

        match ($format) {
            'none' => $statement === [] ? null : throw PasskeyRefused::malformed(),
            'packed' => self::checkPacked($statement, $key, $signed),
            default => throw new PasskeyRefused('format', 'This authenticator answered with an attestation the app does not read; the app asks for none. Try again, or use another passkey or security key.'),
        };

        return new VerifiedRegistration(
            credentialId: $authData->credentialId,
            publicKey: $authData->credentialPublicKey,
            algorithm: $key->algorithm,
            signCount: $authData->signCount,
            aaguid: $authData->aaguid,
            userVerified: $authData->userVerified(),
            backupEligible: $authData->backupEligible(),
            backedUp: $authData->backedUp(),
            format: $format,
        );
    }

    /**
     * A sign-in: the client data's JSON, the authenticator's data and the
     * signature, decoded from base64url; the challenge the server issued,
     * raw; and the credential's stored key (its COSE bytes) and counter.
     */
    public function verifyAssertion(
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
        string $expectedChallenge,
        string $storedPublicKey,
        int $storedSignCount,
        bool $requireUserVerification = true,
    ): VerifiedAssertion {
        $this->checkClientData(ClientData::parse($clientDataJson), self::GET, $expectedChallenge);

        $authData = AuthenticatorData::parse($authenticatorData);
        $this->checkAuthenticatorData($authData, $requireUserVerification);

        $key = CoseKey::fromBytes($storedPublicKey);
        if (!$key->verify($authData->raw . hash('sha256', $clientDataJson, true), $signature)) {
            throw new PasskeyRefused('signature');
        }

        // Counters are optional -- many synced passkeys keep none and send
        // 0 every time -- but one that has started must keep going up.
        $count = $authData->signCount;
        if (($storedSignCount > 0 || $count > 0) && $count <= $storedSignCount) {
            throw new PasskeyRefused('counter', 'This passkey may have been copied, so it was not accepted. Sign in another way, then remove this passkey from your account and add it again.');
        }

        return new VerifiedAssertion($count, $authData->userVerified(), $authData->backedUp());
    }

    private function checkClientData(ClientData $client, string $type, string $expectedChallenge): void
    {
        if ($client->type !== $type) {
            throw new PasskeyRefused('type');
        }
        if ($expectedChallenge === '' || !hash_equals(Base64Url::encode($expectedChallenge), $client->challenge)) {
            throw new PasskeyRefused('challenge', 'This passkey request has expired or was already used. Start again.');
        }
        if (!$this->rp->allows($client->origin)) {
            throw new PasskeyRefused('origin');
        }
        if ($client->crossOrigin) {
            throw new PasskeyRefused('origin');
        }
    }

    private function checkAuthenticatorData(AuthenticatorData $authData, bool $requireUserVerification): void
    {
        if (!hash_equals(hash('sha256', $this->rp->id, true), $authData->rpIdHash)) {
            throw new PasskeyRefused('rp_id');
        }
        if (!$authData->userPresent()) {
            throw new PasskeyRefused('user_verification', 'The authenticator did not confirm you were there. Try again, and touch or unlock it when it asks.');
        }
        if ($requireUserVerification && !$authData->userVerified()) {
            throw new PasskeyRefused('user_verification', 'The authenticator did not check it was you. Try again, and unlock it with your PIN, fingerprint or face when it asks.');
        }
    }

    /**
     * A `packed` attestation (§8.2): its algorithm and signature, by the
     * certificate's key when it has one, and by the credential's own -- a
     * self attestation, in the credential's algorithm -- when it has none.
     *
     * @param array<mixed> $statement
     */
    private static function checkPacked(array $statement, CoseKey $key, string $signed): void
    {
        $algorithm = $statement['alg'] ?? null;
        $signature = $statement['sig'] ?? null;
        if (!\is_int($algorithm) || !$signature instanceof CborBytes) {
            throw PasskeyRefused::malformed();
        }
        if ($algorithm !== CoseKey::ES256 && $algorithm !== CoseKey::RS256) {
            throw new PasskeyRefused('algorithm', 'This authenticator signs with an algorithm the app does not accept. Try another passkey or security key.');
        }

        $chain = $statement['x5c'] ?? null;
        if ($chain === null) {
            if (\count($statement) !== 2 || $algorithm !== $key->algorithm || !$key->verify($signed, $signature->bytes)) {
                throw new PasskeyRefused('signature');
            }

            return;
        }

        if (!\is_array($chain) || $chain === [] || !array_is_list($chain) || !$chain[0] instanceof CborBytes) {
            throw PasskeyRefused::malformed();
        }
        $certificate = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($chain[0]->bytes), 64, "\n") . "-----END CERTIFICATE-----\n";
        $attestationKey = openssl_pkey_get_public($certificate);
        if ($attestationKey === false) {
            throw PasskeyRefused::malformed();
        }
        $details = openssl_pkey_get_details($attestationKey);
        $type = \is_array($details) ? ($details['type'] ?? null) : null;
        $expected = $algorithm === CoseKey::ES256 ? OPENSSL_KEYTYPE_EC : OPENSSL_KEYTYPE_RSA;
        if ($type !== $expected || openssl_verify($signed, $signature->bytes, $attestationKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new PasskeyRefused('signature');
        }
    }
}
