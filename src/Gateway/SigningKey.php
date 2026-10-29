<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

/**
 * An Ed25519 key pair derived from the application key for one purpose:
 * what PHP signs a node command or a console ticket with, so the gateway
 * and the node agents that check them hold the public half alone.
 *
 * Asymmetric where every other token here is a MAC (ADR 0099). A MAC's
 * verifier holds the key that makes one, and these are checked outside PHP
 * -- by a gateway facing browsers, by agents on machines the application
 * does not trust beyond their own node. A compromised agent or edge holding
 * a MAC key could mint commands for every node; holding a public key, it
 * can mint nothing.
 *
 * Derived, as every Signer key is, so the application key stays the one
 * secret and rotating it rotates these: the seed is HKDF-SHA256 of the
 * application key with the purpose in its info, and the pair is Ed25519's
 * from that seed.
 */
final readonly class SigningKey
{
    /** The verifying half, raw: SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES bytes. */
    public string $publicKey;

    private string $secretKey;

    public function __construct(#[\SensitiveParameter] string $appKey, public string $purpose)
    {
        if ($appKey === '') {
            throw new \InvalidArgumentException('A signing key is derived from the application key; set APP_KEY.');
        }
        if ($purpose === '') {
            throw new \InvalidArgumentException('A signing purpose is required; it is what separates one key from another.');
        }
        // Built into PHP since 7.2, and into the official images; a build
        // without it signs nothing rather than something weaker.
        if (!\extension_loaded('sodium')) {
            throw new \LogicException('Node commands and console tickets are signed with Ed25519, which needs the sodium extension.');
        }

        // HKDF rather than the HMAC a Signer's key is: no Signer, whatever
        // purpose it were built for, holds the bytes a seed is made of.
        $pair = sodium_crypto_sign_seed_keypair(hash_hkdf('sha256', $appKey, SODIUM_CRYPTO_SIGN_SEEDBYTES, "tinvar ed25519 seed\0{$purpose}"));
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    /** The public half as URL-safe base64, as it is handed to a gateway or an agent. */
    public function publicKeyText(): string
    {
        return rtrim(strtr(base64_encode($this->publicKey), '+/', '-_'), '=');
    }

    /** A detached signature over $message. */
    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    /** Whether $signature is $publicKey's over $message. */
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        if (\strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || \strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }

    /** Never the secret half in a dump. */
    public function __debugInfo(): array
    {
        return ['purpose' => $this->purpose, 'publicKey' => $this->publicKeyText()];
    }
}
