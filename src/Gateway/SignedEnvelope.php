<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * The envelope a node command and a console ticket travel in:
 * `v1.<payload>.<signature>`, each part URL-safe base64, the payload JSON.
 *
 * The same shape as every signed token here (SignedToken), with an Ed25519
 * signature in place of the MAC, and the same order of checks: the version
 * and the purpose are inside what is signed, so a ticket never reads as a
 * command or the reverse; the signature is checked before the payload is
 * parsed; and a signature or payload in anything but its canonical encoding
 * is refused, so one value has one spelling. This is the format the
 * gateway and the agents implement (ADR 0099):
 *
 *     signed    = "v1." + purpose + "." + base64url(json)
 *     envelope  = "v1." + base64url(json) + "." + base64url(ed25519(signed))
 */
final class SignedEnvelope
{
    public const string VERSION = 'v1';

    /** The longest envelope opened: a command's arguments are capped well below it. */
    public const int MAX_LENGTH = 32_768;

    /** @param array<string, mixed> $payload */
    public static function encode(SigningKey $key, array $payload): string
    {
        $body = Signer::encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::VERSION . '.' . $body . '.' . Signer::encode($key->sign(self::signed($key->purpose, $body)));
    }

    /**
     * The payload, once the signature verifies for $purpose under $publicKey.
     *
     * @return array<string, mixed>
     * @throws TokenError
     */
    public static function decode(string $envelope, string $publicKey, string $purpose): array
    {
        if (\strlen($envelope) > self::MAX_LENGTH) {
            throw new TokenError('longer than any envelope this application signs');
        }
        $parts = explode('.', $envelope);
        if (\count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new TokenError('malformed or unknown envelope version');
        }
        [, $body, $encoded] = $parts;

        $signature = Signer::decode($encoded);
        if ($signature === null || Signer::encode($signature) !== $encoded
            || !SigningKey::verify(self::signed($purpose, $body), $signature, $publicKey)) {
            // Forged, another purpose's, or another key's: one answer.
            throw new TokenError('signature does not verify');
        }

        $json = Signer::decode($body);
        if ($json === null || Signer::encode($json) !== $body) {
            throw new TokenError('payload is not canonically encoded');
        }
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TokenError('payload is not JSON');
        }
        if (!\is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new TokenError('payload is not an object');
        }

        /** @var array<string, mixed> */
        return $payload;
    }

    private static function signed(string $purpose, string $body): string
    {
        return self::VERSION . '.' . $purpose . '.' . $body;
    }
}
