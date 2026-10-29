<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\Target;

/**
 * OpenID Connect discovery: an issuer's configuration and signing keys,
 * fetched through OutboundHttp (tinvar-spec.md, "Outbound requests").
 *
 *     $provider = $discovery->discover('https://accounts.example');
 *     $keys = $discovery->keys($provider);
 *
 * The issuer is the application's -- configuration, or a tenant's
 * connection record -- and is held to what OpenID Connect Discovery 1.0
 * requires of one: https, a host, perhaps a port and a path, no query, no
 * fragment, no credentials, and written as the URL parser writes it, so
 * there is one spelling of it to compare. Its document is at the issuer
 * with `/.well-known/openid-configuration` after it, and is fetched with no
 * redirects: a provider that moved its document has moved its issuer.
 *
 * The document is taken only when its `issuer` is exactly that string --
 * byte for byte, not normalized, since an ID token's `iss` is compared the
 * same way and one that matched loosely here would match loosely there
 * (Discovery 1.0, section 4.3; the mix-up attack of RFC 9207) -- and every
 * endpoint it names is an https URL OutboundHttp would send to. `none` is
 * struck from the signing algorithms, and a document offering nothing else
 * is refused.
 *
 * A key set is fetched from the document's `jwks_uri`, and held to keys
 * that are public: a JWK carrying a private or symmetric part (`d`, `p`,
 * `q`, `dp`, `dq`, `qi`, `oth`, `k`) is dropped, since a verifier handed a
 * symmetric key by the provider's own endpoint is a verifier an `HS256`
 * token can be forged against.
 *
 * Both are kept in memory for an hour (Cache-Control is not read: a
 * provider that rotates keys publishes the next beside the current one).
 * A token signed by a key not in the set asks for the set again through
 * keys(refresh: true), which goes to the provider at most once a minute
 * per issuer, so a stream of tokens naming invented key ids cannot turn
 * this into a request to the provider each. At most MAX_KEPT of each are
 * kept, so issuers tenants name cannot grow a worker without bound.
 * Nothing kept is any request's:
 * an issuer's document and keys are public and the same for every tenant
 * that names it.
 */
final class OidcDiscovery
{
    public const string PATH = '/.well-known/openid-configuration';

    /** The largest document or key set read. */
    public const int MAX_BYTES = 131_072;

    /** Seconds a fetch may take. */
    public const float TIMEOUT = 5.0;

    /** Seconds a document or key set is kept. */
    public const int TTL = 3600;

    /** Seconds between key sets fetched again for a key not found. */
    public const int REFRESH_INTERVAL = 60;

    /** Issuers and key sets kept at once; the oldest is forgotten past it. */
    public const int MAX_KEPT = 256;

    /** A key's members that are never public. */
    private const array PRIVATE_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /** @var array<string, array{OidcProvider, int}> issuer => provider, when it expires */
    private array $providers = [];

    /** @var array<string, array{list<array<string, mixed>>, int, int}> jwks_uri => keys, when they expire, when they were fetched */
    private array $keys = [];

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given */
    public function __construct(private readonly OutboundHttp $http, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /**
     * $issuer, if it is one: https, a host, perhaps a port and a path, no
     * query, fragment or credentials, as the URL parser writes it.
     *
     * @throws \InvalidArgumentException
     */
    public static function issuer(string $issuer): string
    {
        $refused = new \InvalidArgumentException(
            "'{$issuer}' is not an OpenID issuer: https, a host, perhaps a port and a path, and no query or fragment, as https://accounts.example.",
        );
        if (str_contains($issuer, '?') || str_contains($issuer, '#') || str_contains($issuer, '@')) {
            throw $refused;
        }
        try {
            $target = Target::parse($issuer);
        } catch (OutboundRefused) {
            throw $refused;
        }
        $serialized = $target->serialized();
        if ($target->scheme !== 'https' || ($serialized !== $issuer && $serialized !== "{$issuer}/")) {
            throw $refused;
        }

        return $issuer;
    }

    /**
     * The provider at $issuer, from memory or its discovery document.
     *
     * @throws \InvalidArgumentException for an issuer that is not one
     * @throws OidcDiscoveryFailed for a document that is not taken
     * @throws OutboundRefused|\Tinvar\Outbound\OutboundFailed as OutboundHttp throws them
     */
    public function discover(string $issuer): OidcProvider
    {
        $issuer = self::issuer($issuer);
        $now = ($this->clock)();
        $kept = $this->providers[$issuer] ?? null;
        if ($kept !== null && $kept[1] > $now) {
            return $kept[0];
        }

        $document = $this->fetch(rtrim($issuer, '/') . self::PATH, $issuer);

        // Exactly: not trimmed, not case-folded, not with or without a slash.
        if (($document['issuer'] ?? null) !== $issuer) {
            throw new OidcDiscoveryFailed(OidcDiscoveryFailed::ISSUER, "The discovery document at {$issuer} names another issuer; it is not taken.");
        }

        $algorithms = array_values(array_filter(self::strings($document, 'id_token_signing_alg_values_supported', $issuer), static fn (string $alg): bool => strtolower($alg) !== 'none'));
        if ($algorithms === []) {
            throw new OidcDiscoveryFailed(OidcDiscoveryFailed::DOCUMENT, "The discovery document at {$issuer} offers no ID token signing algorithm but none.");
        }

        $provider = new OidcProvider(
            issuer: $issuer,
            authorizationEndpoint: self::endpoint($document, 'authorization_endpoint', $issuer),
            tokenEndpoint: self::endpoint($document, 'token_endpoint', $issuer),
            jwksUri: self::endpoint($document, 'jwks_uri', $issuer),
            userinfoEndpoint: self::endpoint($document, 'userinfo_endpoint', $issuer, required: false),
            endSessionEndpoint: self::endpoint($document, 'end_session_endpoint', $issuer, required: false),
            responseTypes: self::strings($document, 'response_types_supported', $issuer),
            signingAlgorithms: $algorithms,
            codeChallengeMethods: self::strings($document, 'code_challenge_methods_supported', $issuer, required: false),
            scopes: self::strings($document, 'scopes_supported', $issuer, required: false),
        );
        self::strings($document, 'subject_types_supported', $issuer);

        unset($this->providers[$issuer]);
        $this->providers[$issuer] = [$provider, $now + self::TTL];
        if (\count($this->providers) > self::MAX_KEPT) {
            array_shift($this->providers);
        }

        return $provider;
    }

    /**
     * The provider's public signing keys, each a JWK as an array.
     *
     * $refresh asks the provider again -- for a token signed by a key not
     * in the set -- unless it was asked within the last minute.
     *
     * @return list<array<string, mixed>>
     * @throws OidcDiscoveryFailed|OutboundRefused|\Tinvar\Outbound\OutboundFailed
     */
    public function keys(OidcProvider $provider, bool $refresh = false): array
    {
        $now = ($this->clock)();
        $kept = $this->keys[$provider->jwksUri] ?? null;
        if ($kept !== null && ($kept[1] > $now && (!$refresh || $kept[2] > $now - self::REFRESH_INTERVAL))) {
            return $kept[0];
        }

        $set = $this->fetch($provider->jwksUri, $provider->issuer);
        $listed = $set['keys'] ?? null;
        if (!\is_array($listed) || !array_is_list($listed)) {
            throw new OidcDiscoveryFailed(OidcDiscoveryFailed::DOCUMENT, "The key set of {$provider->issuer} has no list of keys.");
        }

        $keys = [];
        foreach ($listed as $key) {
            if (!\is_array($key) || !\is_string($key['kty'] ?? null)) {
                continue;
            }
            if (array_intersect(array_keys($key), self::PRIVATE_MEMBERS) !== []) {
                continue;
            }
            /** @var array<string, mixed> $key */
            $keys[] = $key;
        }

        unset($this->keys[$provider->jwksUri]);
        $this->keys[$provider->jwksUri] = [$keys, $now + self::TTL, $now];
        if (\count($this->keys) > self::MAX_KEPT) {
            array_shift($this->keys);
        }

        return $keys;
    }

    /** Forgets what is kept for $issuer: its document and its keys. */
    public function forget(string $issuer): void
    {
        $provider = $this->providers[$issuer][0] ?? null;
        if ($provider !== null) {
            unset($this->keys[$provider->jwksUri]);
        }
        unset($this->providers[$issuer]);
    }

    /**
     * A JSON object from $url: a 200, no redirect, within the cap.
     *
     * @return array<string, mixed>
     */
    private function fetch(string $url, string $issuer): array
    {
        $response = $this->http->send(new OutboundRequest(
            'GET',
            $url,
            ['accept' => 'application/json'],
            timeout: self::TIMEOUT,
            maxResponseBytes: self::MAX_BYTES,
        ));
        if ($response->status !== 200) {
            throw new OidcDiscoveryFailed(OidcDiscoveryFailed::STATUS, "{$issuer}'s " . (str_ends_with($url, self::PATH) ? 'discovery document' : 'key set') . " answered {$response->status}.");
        }

        try {
            $document = $response->json();
        } catch (\JsonException) {
            $document = null;
        }
        if (!\is_array($document) || ($document !== [] && array_is_list($document))) {
            throw new OidcDiscoveryFailed(OidcDiscoveryFailed::DOCUMENT, "What {$issuer} answered is not a JSON object.");
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * An endpoint the document names, as an https URL OutboundHttp would
     * send to.
     *
     * @param array<string, mixed> $document
     * @return ($required is true ? string : string|null)
     */
    private static function endpoint(array $document, string $field, string $issuer, bool $required = true): ?string
    {
        $url = $document[$field] ?? null;
        if ($url === null && !$required) {
            return null;
        }
        if (!\is_string($url) || str_contains($url, '#')) {
            throw new OidcDiscoveryFailed(OidcDiscoveryFailed::ENDPOINT, "{$issuer}'s {$field} is missing or not a URL.");
        }
        try {
            $target = Target::parse($url);
        } catch (OutboundRefused) {
            throw new OidcDiscoveryFailed(OidcDiscoveryFailed::ENDPOINT, "{$issuer}'s {$field} is not a URL a request is sent to.");
        }
        if ($target->scheme !== 'https') {
            throw new OidcDiscoveryFailed(OidcDiscoveryFailed::ENDPOINT, "{$issuer}'s {$field} is not https.");
        }

        return $target->serialized();
    }

    /**
     * A list of strings the document names.
     *
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private static function strings(array $document, string $field, string $issuer, bool $required = true): array
    {
        $values = $document[$field] ?? null;
        if ($values === null && !$required) {
            return [];
        }
        if (!\is_array($values) || !array_is_list($values) || ($required && $values === [])) {
            throw new OidcDiscoveryFailed(OidcDiscoveryFailed::DOCUMENT, "{$issuer}'s {$field} is missing or not a list.");
        }
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new OidcDiscoveryFailed(OidcDiscoveryFailed::DOCUMENT, "{$issuer}'s {$field} is not a list of strings.");
            }
        }

        /** @var list<string> $values */
        return $values;
    }
}
