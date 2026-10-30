<?php

declare(strict_types=1);

namespace Tinvar\Tests\Identity\Fixtures;

use Tinvar\Identity\Pkce;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Support\Signer;

/**
 * An OpenID provider in this process, answering through FakeOutboundHttp:
 * its discovery document, its key set, an authorization step a test calls
 * as the browser would reach it, and a token endpoint that holds a code to
 * its PKCE challenge and answers with an ID token the test can shape.
 */
final class FakeOidcProvider
{
    public const string ISSUER = 'https://idp.example';

    public const string CLIENT = 'shop-client';

    /** @var array<string, \OpenSSLAsymmetricKey> kid => private key */
    private static array $keys = [];

    /** @var array<string, array{challenge: string, nonce: string, client: string, redirect: string}> */
    public array $codes = [];

    /** @var list<OutboundRequest> token requests, as they came */
    public array $tokenRequests = [];

    /** Shapes the next ID token's header and claims, given the defaults. */
    public ?\Closure $shape = null;

    /** @var list<string> the kids the key set lists */
    public array $published = ['rsa-1', 'ec-1'];

    /** @var array<string, mixed> discovery document fields laid over the defaults */
    public array $document = [];

    public int $now = 1_800_000_000;

    /** @var array<string, array<string, mixed>> access token => the person it is for */
    public array $accessTokens = [];

    /** @var array<string, array<string, mixed>> refresh token => the claims of the sign-in it renews */
    public array $refreshTokens = [];

    /** @var list<OutboundRequest> userinfo requests, as they came */
    public array $userinfoRequests = [];

    /** @var array<string, mixed> userinfo claims laid over the person's */
    public array $userinfo = [];

    public string $userinfoType = 'application/json';

    /** Whether a refresh answers with an ID token. */
    public bool $refreshIdToken = true;

    /** Shapes a refresh's ID token, as $shape a sign-in's. */
    public ?\Closure $refreshShape = null;

    public readonly FakeOutboundHttp $http;

    public function __construct()
    {
        $this->http = new FakeOutboundHttp(fn (OutboundRequest $request): OutboundResponse => $this->answer($request));
    }

    public static function key(string $kid): \OpenSSLAsymmetricKey
    {
        return self::$keys[$kid] ??= match (true) {
            str_starts_with($kid, 'ed') => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]),
            str_starts_with($kid, 'ec') => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            str_starts_with($kid, 'weak') => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]),
            default => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]),
        } ?: throw new \RuntimeException('Cannot make a key.');
    }

    /** @return array<string, mixed> the public JWK of $kid */
    public static function jwk(string $kid): array
    {
        $details = (array) openssl_pkey_get_details(self::key($kid));
        if (isset($details['ed25519'])) {
            return ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => $kid, 'use' => 'sig', 'x' => Signer::encode($details['ed25519']['pub_key'])];
        }
        if (isset($details['ec'])) {
            return ['kty' => 'EC', 'crv' => 'P-256', 'kid' => $kid, 'use' => 'sig',
                'x' => Signer::encode(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
                'y' => Signer::encode(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))];
        }

        return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'n' => Signer::encode($details['rsa']['n']), 'e' => Signer::encode($details['rsa']['e'])];
    }

    /**
     * The browser at the authorization endpoint: the person signs in, and
     * is sent back with a code -- the query the redirect URI receives.
     *
     * @return array<string, string>
     */
    public function authorize(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $code = bin2hex(random_bytes(12));
        $this->codes[$code] = [
            'challenge' => (string) $query['code_challenge'],
            'nonce' => (string) $query['nonce'],
            'client' => (string) $query['client_id'],
            'redirect' => (string) $query['redirect_uri'],
        ];

        return ['code' => $code, 'state' => (string) $query['state'], 'iss' => self::ISSUER];
    }

    /**
     * A signed JWT.
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public static function sign(array $header, array $claims, string $kid): string
    {
        $signing = Signer::encode((string) json_encode($header)) . '.' . Signer::encode((string) json_encode($claims));
        $alg = (string) ($header['alg'] ?? 'RS256');
        $digest = match (substr($alg, 2)) {
            '384' => OPENSSL_ALGO_SHA384,
            '512' => OPENSSL_ALGO_SHA512,
            default => OPENSSL_ALGO_SHA256,
        };
        match (true) {
            $alg === 'EdDSA' || $alg === 'Ed25519' => openssl_sign($signing, $signature, self::key($kid), 0),
            str_starts_with($alg, 'PS') => openssl_sign($signing, $signature, self::key($kid), $digest, OPENSSL_PKCS1_PSS_PADDING),
            default => openssl_sign($signing, $signature, self::key($kid), $digest),
        };
        if (str_starts_with($alg, 'ES')) {
            $signature = self::rawEcdsa((string) $signature);
        }

        return $signing . '.' . Signer::encode((string) $signature);
    }

    private function answer(OutboundRequest $request): OutboundResponse
    {
        $json = static fn (mixed $body, int $status = 200): OutboundResponse => new OutboundResponse($status, ['content-type' => ['application/json']], (string) json_encode($body));

        return match ($request->url) {
            self::ISSUER . '/.well-known/openid-configuration' => $json(array_merge([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER . '/authorize',
                'token_endpoint' => self::ISSUER . '/token',
                'jwks_uri' => self::ISSUER . '/jwks',
                'userinfo_endpoint' => self::ISSUER . '/userinfo',
                'end_session_endpoint' => self::ISSUER . '/logout',
                'response_types_supported' => ['code'],
                'subject_types_supported' => ['public'],
                'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
                'code_challenge_methods_supported' => ['S256'],
            ], $this->document)),
            self::ISSUER . '/jwks' => $json(['keys' => array_map(self::jwk(...), $this->published)]),
            self::ISSUER . '/token' => $this->token($request),
            self::ISSUER . '/userinfo' => $this->userinfo($request),
            default => new OutboundResponse(404),
        };
    }

    private function userinfo(OutboundRequest $request): OutboundResponse
    {
        $this->userinfoRequests[] = $request;
        $person = $this->accessTokens[substr($request->headers['authorization'] ?? '', \strlen('Bearer '))] ?? null;
        if ($person === null) {
            return new OutboundResponse(401);
        }

        return new OutboundResponse(200, ['content-type' => [$this->userinfoType]], (string) json_encode([...$person, ...$this->userinfo]));
    }

    private function token(OutboundRequest $request): OutboundResponse
    {
        $this->tokenRequests[] = $request;
        parse_str($request->body, $form);
        if (($form['grant_type'] ?? null) === 'refresh_token') {
            return $this->refreshed((string) ($form['refresh_token'] ?? ''));
        }
        $code = $this->codes[(string) ($form['code'] ?? '')] ?? null;
        unset($this->codes[(string) ($form['code'] ?? '')]);
        if ($code === null || Pkce::challenge((string) ($form['code_verifier'] ?? '')) !== $code['challenge']
            || ($form['redirect_uri'] ?? null) !== $code['redirect']) {
            return new OutboundResponse(400, [], '{"error":"invalid_grant"}');
        }

        $header = ['alg' => 'RS256', 'kid' => 'rsa-1', 'typ' => 'JWT'];
        $claims = [
            'iss' => self::ISSUER,
            'sub' => 'person-42',
            'aud' => $code['client'],
            'exp' => $this->now + 300,
            'iat' => $this->now,
            'nonce' => $code['nonce'],
            'email' => 'ada@example.com',
            'email_verified' => true,
        ];
        $kid = 'rsa-1';
        $token = null;
        if ($this->shape !== null) {
            [$header, $claims, $kid, $token] = ($this->shape)($header, $claims) + [2 => (string) ($header['kid'] ?? 'rsa-1'), 3 => null];
        }

        return $this->granted((string) ($claims['sub'] ?? ''), $token ?? self::sign($header, $claims, $kid), $claims);
    }

    /**
     * A refresh: a refresh token it issued, spent and replaced -- this
     * provider rotates them -- for new tokens and, unless $refreshIdToken
     * says otherwise, a new ID token for the same person.
     */
    private function refreshed(string $refreshToken): OutboundResponse
    {
        $granted = $this->refreshTokens[$refreshToken] ?? null;
        unset($this->refreshTokens[$refreshToken]);
        if ($granted === null) {
            return new OutboundResponse(400, [], '{"error":"invalid_grant"}');
        }

        $claims = [...$granted, 'iat' => $this->now, 'exp' => $this->now + 300];
        unset($claims['nonce']);
        $header = ['alg' => 'RS256', 'kid' => 'rsa-1', 'typ' => 'JWT'];
        $kid = 'rsa-1';
        $token = null;
        if ($this->refreshShape !== null) {
            [$header, $claims, $kid, $token] = ($this->refreshShape)($header, $claims) + [2 => (string) ($header['kid'] ?? 'rsa-1'), 3 => null];
        }

        return $this->granted((string) ($granted['sub'] ?? ''), $this->refreshIdToken ? ($token ?? self::sign($header, $claims, $kid)) : null, $granted);
    }

    /** @param array<string, mixed> $claims the person's, for the refresh token to renew */
    private function granted(string $sub, ?string $idToken, array $claims): OutboundResponse
    {
        $access = 'at-' . bin2hex(random_bytes(8));
        $refresh = 'rt-' . bin2hex(random_bytes(8));
        $this->accessTokens[$access] = ['sub' => $sub, 'email' => 'ada@example.com', 'email_verified' => true];
        $this->refreshTokens[$refresh] = $claims;

        return new OutboundResponse(200, ['content-type' => ['application/json']], (string) json_encode(array_filter([
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'refresh_token' => $refresh,
            'id_token' => $idToken,
        ], static fn (mixed $value): bool => $value !== null)));
    }

    /** OpenSSL's DER ECDSA signature as JWS's r and s side by side. */
    private static function rawEcdsa(string $der): string
    {
        $offset = 2;
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $length = \ord($der[$offset + 1]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0] . $parts[1];
    }
}
