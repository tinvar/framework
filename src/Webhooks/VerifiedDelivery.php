<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * What a WebhookVerifier proved of a delivery: when it was signed, the
 * nonce that makes it its own, how long the nonce is kept, and who signed
 * it -- a digest of the secret, never the secret -- so a delivery verified
 * for one tenant is a replay at another's that shares the secret.
 */
final readonly class VerifiedDelivery
{
    /** A nonce: printable ASCII, no space, within the 128 bytes a nonce store keeps (DatabaseNonceStore::MAX_NONCE_BYTES). */
    public const string NONCE_SHAPE = '/^[\x21-\x7e]{8,128}\z/';

    /**
     * @param int $timestamp when the sender signed it, seconds since the epoch
     * @param string $nonce what makes the delivery its own: a sender's nonce, or an event id the signature covers
     * @param int $expiresAt until when the nonce is kept: past it, the verifier refuses the delivery on its timestamp alone
     * @param string $signer a digest naming the secret it was signed with (signerOf())
     */
    public function __construct(
        public int $timestamp,
        public string $nonce,
        public int $expiresAt,
        public string $signer,
    ) {
        if (preg_match(self::NONCE_SHAPE, $nonce) !== 1) {
            throw new \InvalidArgumentException('A verified delivery\'s nonce is 8 to 128 printable characters, with no space.');
        }
        if ($expiresAt < $timestamp) {
            throw new \InvalidArgumentException('A verified delivery\'s nonce is kept until after it was signed, not before.');
        }
        if (preg_match('/^[0-9a-f]{64}\z/', $signer) !== 1) {
            throw new \InvalidArgumentException('A verified delivery names its signer by signerOf(), a SHA-256 digest of the secret.');
        }
    }

    /** The digest that names a secret in a nonce's scope, and says nothing of it. */
    public static function signerOf(#[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', 'tinvar-webhook-nonce-scope', $secret);
    }
}
