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

    public readonly FakeOutboundHttp $http;

    public function __construct()
    {
        $this->http = new FakeOutboundHttp(fn (OutboundRequest $request): OutboundResponse => $this->answer($request));
    }

    public static function key(string $kid): \OpenSSLAsymmetricKey
    {
        return self::$keys[$kid] ??= match (true) {
            str_starts_with($kid, 'ec') => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']),
            str_starts_with($kid, 'weak') => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]),
            default => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]),
        } ?: throw new \RuntimeException('Cannot make a key.');
    }

    /** @return array<string, mixed> the public JWK of $kid */
    public static function jwk(string $kid): array
    {
        $details = (array) openssl_pkey_get_details(self::key($kid));
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
        openssl_sign($signing, $signature, self::key($kid), $digest);
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
                'response_types_supported' => ['code'],
                'subject_types_supported' => ['public'],
                'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
                'code_challenge_methods_supported' => ['S256'],
            ], $this->document)),
            self::ISSUER . '/jwks' => $json(['keys' => array_map(self::jwk(...), $this->published)]),
            self::ISSUER . '/token' => $this->token($request),
            default => new OutboundResponse(404),
        };
    }

    private function token(OutboundRequest $request): OutboundResponse
    {
        $this->tokenRequests[] = $request;
        parse_str($request->body, $form);
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

        return new OutboundResponse(200, ['content-type' => ['application/json']], (string) json_encode([
            'access_token' => 'at',
            'token_type' => 'Bearer',
            'id_token' => $token ?? self::sign($header, $claims, $kid),
        ]));
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
