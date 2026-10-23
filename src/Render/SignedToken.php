<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Support\Signer;

/**
 * The envelope every signed token shares: `v1.<payload>.<mac>`, URL-safe.
 *
 * Separated from the claims so that the ORDER of verification -- which is
 * the part that is easy to get subtly wrong -- is written once.
 */
final class SignedToken
{
    public const string VERSION = 'v1';

    /**
     * @param string $purpose what KIND of token this is, bound into the MAC
     * @param array<string, mixed> $payload
     */
    public static function encode(Signer $signer, string $purpose, array $payload): string
    {
        $body = Signer::encode(json_encode($payload, JSON_THROW_ON_ERROR));

        // The version AND the purpose are inside the signed string, not
        // merely prefixed to it.
        //
        // The version, so a token cannot be replayed under a later one
        // whose fields mean something else. The purpose, because a route
        // payload is a strict SUBSET of a fragment payload and nothing in
        // the claims distinguishes them -- so without a type bound into the
        // MAC, any fragment token decodes cleanly as a route token. Every
        // <Deferred> boundary puts one in page source and /_bootstrap hands
        // them to anonymous visitors, so they are the cheap ones to obtain
        // and were, in effect, universal.
        //
        // Binding it here rather than relying on a separate key per purpose
        // means the separation survives an application wiring one signer
        // into two places -- which is exactly what happened.
        return self::VERSION . '.' . $body . '.' . Signer::encode(
            $signer->sign(self::signed($purpose, $body))
        );
    }

    /**
     * Verifies the envelope and returns the payload map.
     *
     * @return array<string, mixed>
     * @throws TokenError
     */
    public static function decode(string $token, Signer $signer, string $purpose): array
    {
        $parts = explode('.', $token);
        if (\count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new TokenError('malformed or unknown token version');
        }

        [, $payload, $mac] = $parts;

        $signature = Signer::decode($mac);

        // Compared as the CANONICAL encoding, not just as bytes. base64url
        // leaves slack in the final character, so several distinct token
        // STRINGS decode to one MAC; accepting all of them is not a forgery,
        // but it defeats anything that keys on the token string.
        if ($signature !== null && Signer::encode($signature) !== $mac) {
            throw new TokenError('token is not canonically encoded');
        }

        // Checked BEFORE the payload is parsed: everything after this point
        // treats the contents as data this server issued, and parsing first
        // would mean parsing attacker-controlled JSON.
        if ($signature === null || !$signer->verify(self::signed($purpose, $payload), $signature)) {
            // Covers both "forged" and "a token of another kind", which the
            // caller must not be able to tell apart.
            throw new TokenError('signature does not verify');
        }

        $json = Signer::decode($payload);
        if ($json === null) {
            throw new TokenError('payload is not valid encoding');
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TokenError('payload is not valid JSON');
        }

        if (!\is_array($decoded)) {
            throw new TokenError('payload is not an object');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /** What the MAC is actually over. */
    private static function signed(string $purpose, string $body): string
    {
        return self::VERSION . '.' . $purpose . '.' . $body;
    }
}
