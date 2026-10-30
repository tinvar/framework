<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Support\Signer;

/**
 * Whether an ID token is the provider's, for this client, for this sign-in
 * (OpenID Connect Core 1.0, 3.1.3.7).
 *
 * In this order, each failure an IdTokenRefused:
 *
 *  - a compact JWS of three parts, under MAX_BYTES -- never an encrypted
 *    token, never a header with `crit`, whose extensions nothing here
 *    knows;
 *  - its `alg` one this verifier takes (ALGORITHMS: RSA and ECDSA with
 *    SHA-2) *and* one the provider's discovery document offers. `none` is
 *    in neither, nor is any `HS` algorithm: an HMAC keyed with what the
 *    provider published as a public key is a token anyone can forge, the
 *    oldest confusion of JWT libraries;
 *  - its key from the provider's own key set (OidcDiscovery::keys()), by
 *    `kid`, of the type the algorithm takes -- and if no key matches, the
 *    set asked for once more, as a provider that rotated would need. A key
 *    named in the token's own header (`jwk`, `jku`, `x5u`, `x5c`) is never
 *    read: the token does not get to say what verifies it;
 *  - its signature, by that key;
 *  - `iss` exactly the provider's issuer; `aud` naming this client, with
 *    `azp` naming it too where `aud` names anyone else; `exp` in the
 *    future and `iat` and `nbf` not, each within LEEWAY; a `sub`; and
 *    `nonce` the one this sign-in sent, compared in constant time.
 */
final readonly class IdTokenVerifier
{
    /** Each algorithm taken: the key type, the curve for ECDSA, the digest. */
    public const array ALGORITHMS = [
        'RS256' => ['RSA', null, OPENSSL_ALGO_SHA256],
        'RS384' => ['RSA', null, OPENSSL_ALGO_SHA384],
        'RS512' => ['RSA', null, OPENSSL_ALGO_SHA512],
        'ES256' => ['EC', 'P-256', OPENSSL_ALGO_SHA256],
        'ES384' => ['EC', 'P-384', OPENSSL_ALGO_SHA384],
        'ES512' => ['EC', 'P-521', OPENSSL_ALGO_SHA512],
    ];

    /** The largest token read. */
    public const int MAX_BYTES = 16_384;

    /** Seconds of clock difference allowed either way. */
    public const int LEEWAY = 60;

    /**
     * @param list<string>|null $algorithms the algorithms an application narrows these to; all of ALGORITHMS when null
     */
    public function __construct(private ?array $algorithms = null)
    {
        foreach ($algorithms ?? [] as $alg) {
            if (!isset(self::ALGORITHMS[$alg])) {
                throw new \InvalidArgumentException("'{$alg}' is not an algorithm an ID token is verified with here: " . implode(', ', array_keys(self::ALGORITHMS)) . '.');
            }
        }
    }

    /**
     * @param \Closure(bool): list<array<string, mixed>> $keys the provider's key set, asked again when given true
     * @throws IdTokenRefused
     */
    public function verify(
        #[\SensitiveParameter]
        string $token,
        OidcProvider $provider,
        \Closure $keys,
        string $clientId,
        #[\SensitiveParameter]
        string $nonce,
        int $now,
    ): IdToken {
        if (\strlen($token) > self::MAX_BYTES) {
            throw new IdTokenRefused(IdTokenRefused::MALFORMED, 'The ID token is larger than one is.');
        }
        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            throw new IdTokenRefused(IdTokenRefused::MALFORMED, 'The ID token is not a signed JWT of three parts.');
        }
        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = self::object($encodedHeader, 'header');
        $signature = Signer::decode($encodedSignature);
        if ($signature === null || $signature === '' || Signer::encode($signature) !== $encodedSignature) {
            throw new IdTokenRefused(IdTokenRefused::MALFORMED, 'The ID token\'s signature is not base64url.');
        }
        if (\array_key_exists('crit', $header)) {
            throw new IdTokenRefused(IdTokenRefused::MALFORMED, 'The ID token names critical extensions, which are not understood here.');
        }

        // The algorithm: this verifier's, narrowed by the application, and
        // the provider's. Never `none`, never HMAC.
        $alg = $header['alg'] ?? null;
        $allowed = array_intersect($this->algorithms ?? array_keys(self::ALGORITHMS), $provider->signingAlgorithms);
        if (!\is_string($alg) || !isset(self::ALGORITHMS[$alg]) || !\in_array($alg, $allowed, true)) {
            throw new IdTokenRefused(IdTokenRefused::ALGORITHM, 'The ID token is signed with an algorithm not allowed for this provider.');
        }
        [$kty, $curve, $digest] = self::ALGORITHMS[$alg];

        $kid = $header['kid'] ?? null;
        if ($kid !== null && !\is_string($kid)) {
            throw new IdTokenRefused(IdTokenRefused::MALFORMED, 'The ID token\'s key id is not a string.');
        }
        $jwk = self::key($keys(false), $kid, $kty, $alg) ?? self::key($keys(true), $kid, $kty, $alg)
            ?? throw new IdTokenRefused(IdTokenRefused::KEY, 'No key of the provider\'s signs with this algorithm under this key id.');

        if ($kty === 'EC') {
            $signature = Jwk::ecdsaSignature($signature, (string) $curve)
                ?? throw new IdTokenRefused(IdTokenRefused::SIGNATURE, 'The ID token\'s signature is not its curve\'s size.');
        }
        $verified = openssl_verify("{$encodedHeader}.{$encodedClaims}", $signature, Jwk::publicKey($jwk, $kty, $curve), $digest);
        if ($verified !== 1) {
            while (openssl_error_string() !== false) {
                // Drained: OpenSSL's queue is the process's, and the next caller's.
            }
            throw new IdTokenRefused(IdTokenRefused::SIGNATURE, 'The ID token\'s signature does not verify.');
        }

        // Only now are the claims read: up to here they were anyone's.
        $claims = self::object($encodedClaims, 'claims');

        if (($claims['iss'] ?? null) !== $provider->issuer) {
            throw new IdTokenRefused(IdTokenRefused::ISSUER, 'The ID token names another issuer.');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = \is_string($aud) ? [$aud] : (\is_array($aud) && array_is_list($aud) ? $aud : []);
        if (!\in_array($clientId, $audiences, true)) {
            throw new IdTokenRefused(IdTokenRefused::AUDIENCE, 'The ID token is not for this client.');
        }
        $azp = $claims['azp'] ?? null;
        if ((\count($audiences) > 1 || $azp !== null) && $azp !== $clientId) {
            throw new IdTokenRefused(IdTokenRefused::AUDIENCE, 'The ID token was issued to another party.');
        }

        $exp = $claims['exp'] ?? null;
        if (!\is_int($exp) || $exp <= $now - self::LEEWAY) {
            throw new IdTokenRefused(IdTokenRefused::EXPIRED, 'The ID token has expired, or says no expiry.');
        }
        $iat = $claims['iat'] ?? null;
        if (!\is_int($iat) || $iat > $now + self::LEEWAY) {
            throw new IdTokenRefused(IdTokenRefused::NOT_YET, 'The ID token was issued in the future, or says no time.');
        }
        $nbf = $claims['nbf'] ?? null;
        if ($nbf !== null && (!\is_int($nbf) || $nbf > $now + self::LEEWAY)) {
            throw new IdTokenRefused(IdTokenRefused::NOT_YET, 'The ID token is not valid yet.');
        }

        $sent = $claims['nonce'] ?? null;
        if (!\is_string($sent) || $nonce === '' || !hash_equals($nonce, $sent)) {
            throw new IdTokenRefused(IdTokenRefused::NONCE, 'The ID token is not for this sign-in: its nonce is another\'s.');
        }

        $sub = $claims['sub'] ?? null;
        if (!\is_string($sub) || $sub === '' || \strlen($sub) > 255) {
            throw new IdTokenRefused(IdTokenRefused::SUBJECT, 'The ID token names no subject.');
        }

        return new IdToken($provider->issuer, $sub, $claims);
    }

    /**
     * The key $kid names, of $kty, usable for signatures by $alg; or the one
     * such key when the token names none.
     *
     * @param list<array<string, mixed>> $keys
     * @return array<string, mixed>|null
     */
    private static function key(array $keys, ?string $kid, string $kty, string $alg): ?array
    {
        $fits = array_values(array_filter($keys, static fn (array $key): bool => ($key['kty'] ?? null) === $kty
            && ($key['use'] ?? 'sig') === 'sig'
            && ($key['alg'] ?? $alg) === $alg
            && ($kid === null || ($key['kid'] ?? null) === $kid)));

        return \count($fits) === 1 ? $fits[0] : null;
    }

    /** @return array<string, mixed> */
    private static function object(string $encoded, string $what): array
    {
        $json = Signer::decode($encoded);
        try {
            $decoded = $json === null ? null : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (!\is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new IdTokenRefused(IdTokenRefused::MALFORMED, "The ID token's {$what} is not a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
