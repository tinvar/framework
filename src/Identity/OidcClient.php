<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Config\Secret;
use Tinvar\Http\NonceStore;
use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * Signing in through an OpenID provider: the authorization code flow with
 * PKCE (OpenID Connect Core 1.0, 3.1; RFC 7636), for one client of one
 * issuer.
 *
 *     $url = $client->start($sessionId, returnTo: '/account');    // redirect the browser there
 *     $signIn = $client->finish($sessionId, $request->query);      // at the redirect URI
 *
 * start() sends the browser to the provider's authorization endpoint with
 * a `state`, a `nonce` and an S256 `code_challenge`, all three bound to the
 * session that asked: the state is a token signed under the application
 * key naming that session (hashed), the issuer, the client, the path to
 * return to, a random id and ten minutes; the nonce and the verifier are
 * MACs of the id and the session, derived again at the finish and stored
 * nowhere. So an answer is taken only by the session that began the
 * sign-in -- a code lifted from a URL or planted in another browser
 * redeems nothing (login CSRF) -- and once: the state's id is claimed in a
 * NonceStore as the answer is taken.
 *
 * finish() then holds the answer to the provider (RFC 9207's `iss`, where
 * the provider sends one, exactly the issuer), exchanges the code at the
 * token endpoint -- through OutboundHttp, held to that endpoint's origin
 * alone (Destinations), no redirect followed, the client's secret, if it
 * has one, in Basic authentication -- and verifies the ID token
 * (IdTokenVerifier): signature by the discovered key set, exact issuer,
 * this client as audience, expiry, and the nonce this session sent. Access
 * and refresh tokens are not kept: a sign-in needs who, not a grant.
 *
 * A provider whose discovery document does not offer S256 is refused at
 * start(): without PKCE a code intercepted on its way back redeems.
 */
final class OidcClient
{
    public const string STATE_PURPOSE = 'oidc-state';

    /** The NonceStore scope an answered state's id is claimed under. */
    public const string STATE_SCOPE = 'oidc-state';

    /** Seconds a sign-in may take at the provider. */
    public const int TTL = 600;

    /** The largest token response read. */
    public const int MAX_TOKEN_RESPONSE = 65_536;

    /** The longest path a sign-in goes on to. */
    public const int MAX_RETURN = 512;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param Signer $signer from the application key, for OidcClient::STATE_PURPOSE: signs states
     *        and derives nonces and verifiers
     * @param NonceStore $states where answered states are claimed, shared by every worker and server
     * @param string $redirectUri this application's redirect URI, exactly as registered with the provider
     * @param list<string> $scopes `openid` and what else the application asks for
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly OutboundHttp $http,
        private readonly Signer $signer,
        private readonly NonceStore $states,
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $redirectUri,
        #[\SensitiveParameter]
        private readonly ?Secret $clientSecret = null,
        private readonly array $scopes = ['openid', 'email', 'profile'],
        private readonly IdTokenVerifier $verifier = new IdTokenVerifier(),
        ?\Closure $clock = null,
    ) {
        OidcDiscovery::issuer($issuer);
        if ($clientId === '' || !\in_array('openid', $scopes, true)) {
            throw new \InvalidArgumentException('An OpenID client has a client id, and asks for the openid scope.');
        }
        foreach ($scopes as $scope) {
            if (preg_match('/^[\x21\x23-\x5b\x5d-\x7e]+\z/', $scope) !== 1) {
                throw new \InvalidArgumentException('A scope is one token of printable characters, with no quote or backslash.');
            }
        }
        if (!str_starts_with($redirectUri, 'https://') && !str_starts_with($redirectUri, 'http://localhost') && !str_starts_with($redirectUri, 'http://127.0.0.1')) {
            throw new \InvalidArgumentException('A redirect URI is https, or plain http on this machine alone.');
        }
        $this->clock = $clock ?? time(...);
    }

    /**
     * The URL to send the browser to, to sign in at the provider.
     *
     * @param string $sessionId the session the sign-in is for: only it can finish it
     * @param string $returnTo a path on this application to go on to afterwards
     * @throws OidcSignInFailed when the provider does not take PKCE
     */
    public function start(#[\SensitiveParameter] string $sessionId, string $returnTo = '/'): string
    {
        if ($sessionId === '') {
            throw new \InvalidArgumentException('A sign-in is begun by a session: there is none to bind it to.');
        }
        $provider = $this->discovery->discover($this->issuer);
        if (!$provider->supportsPkce()) {
            throw new OidcSignInFailed(OidcSignInFailed::PROVIDER, "{$this->issuer} does not take PKCE's S256; a sign-in without it is not begun.");
        }

        $id = Signer::encode(random_bytes(16));
        $now = ($this->clock)();
        $state = SignedToken::encode($this->signer, self::STATE_PURPOSE, [
            'i' => $this->issuer,
            'c' => $this->clientId,
            's' => self::sessionHash($sessionId),
            'r' => self::returnPath($returnTo),
            'n' => $id,
            'e' => $now + self::TTL,
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
            'nonce' => $this->nonce($id, $sessionId),
            'code_challenge' => Pkce::challenge($this->verifier($id, $sessionId)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $provider->authorizationEndpoint . (str_contains($provider->authorizationEndpoint, '?') ? '&' : '?') . $query;
    }

    /**
     * Takes the provider's answer at the redirect URI.
     *
     * @param string $sessionId the session the answer came back to
     * @param array<array-key, mixed> $query the redirect's query parameters
     * @throws OidcSignInFailed
     */
    public function finish(#[\SensitiveParameter] string $sessionId, array $query): OidcSignIn
    {
        $now = ($this->clock)();

        // The state first: until it verifies, nothing else in the answer is
        // this session's, an error included.
        $raw = $query['state'] ?? null;
        if (!\is_string($raw) || \strlen($raw) > 4096) {
            throw new OidcSignInFailed(OidcSignInFailed::STATE, 'The answer carries no state.');
        }
        try {
            $state = SignedToken::decode($raw, $this->signer, self::STATE_PURPOSE);
        } catch (TokenError $e) {
            throw new OidcSignInFailed(OidcSignInFailed::STATE, "The answer's state is refused: {$e->getMessage()}.");
        }
        $id = \is_string($state['n'] ?? null) ? $state['n'] : '';
        $expires = \is_int($state['e'] ?? null) ? $state['e'] : 0;
        if ($id === '' || $expires <= $now) {
            throw new OidcSignInFailed(OidcSignInFailed::STATE, 'The sign-in took too long, or its state is not one.');
        }
        if (($state['i'] ?? null) !== $this->issuer || ($state['c'] ?? null) !== $this->clientId) {
            throw new OidcSignInFailed(OidcSignInFailed::STATE, 'The answer is for another provider or client.');
        }
        if ($sessionId === '' || !\is_string($state['s'] ?? null) || !hash_equals($state['s'], self::sessionHash($sessionId))) {
            throw new OidcSignInFailed(OidcSignInFailed::STATE, 'The answer came back to another session than the one that began the sign-in.');
        }

        // RFC 9207: an answer naming its issuer must name this one -- the
        // mix-up attack sends one provider's answer to another's client.
        if (\array_key_exists('iss', $query) && $query['iss'] !== $this->issuer) {
            throw new OidcSignInFailed(OidcSignInFailed::STATE, 'The answer names another issuer.');
        }

        // Once: whatever comes of it, this state is spent.
        if (!$this->states->claim(self::STATE_SCOPE, $id, $expires, $now)) {
            throw new OidcSignInFailed(OidcSignInFailed::STATE, 'The answer was taken before.');
        }

        if (\array_key_exists('error', $query)) {
            throw new OidcSignInFailed(OidcSignInFailed::DENIED, 'The provider answered with an error; the sign-in was cancelled or refused.');
        }
        $code = $query['code'] ?? null;
        if (!\is_string($code) || $code === '' || \strlen($code) > 4096) {
            throw new OidcSignInFailed(OidcSignInFailed::TOKEN, 'The answer carries no code.');
        }

        $provider = $this->discovery->discover($this->issuer);
        $idToken = $this->exchange($provider, $code, $this->verifier($id, $sessionId));

        try {
            $verified = $this->verifier->verify(
                $idToken,
                $provider,
                fn (bool $refresh): array => $this->discovery->keys($provider, $refresh),
                $this->clientId,
                $this->nonce($id, $sessionId),
                $now,
            );
        } catch (IdTokenRefused $e) {
            throw new OidcSignInFailed(OidcSignInFailed::ID_TOKEN, "The ID token is refused ({$e->reason}).", $e);
        }

        return new OidcSignIn($verified, \is_string($state['r'] ?? null) ? $state['r'] : '/');
    }

    /** The ID token the code is exchanged for. */
    private function exchange(OidcProvider $provider, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier): string
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $verifier,
        ];
        $headers = ['accept' => 'application/json', 'content-type' => 'application/x-www-form-urlencoded'];
        if ($this->clientSecret !== null) {
            // RFC 6749, 2.3.1: each form-encoded before they are joined.
            $headers['authorization'] = 'Basic ' . base64_encode(rawurlencode($this->clientId) . ':' . rawurlencode($this->clientSecret->reveal()));
        } else {
            $form['client_id'] = $this->clientId;
        }

        $response = $this->http->send(new OutboundRequest(
            'POST',
            $provider->tokenEndpoint,
            $headers,
            http_build_query($form, '', '&', PHP_QUERY_RFC3986),
            timeout: OidcDiscovery::TIMEOUT * 2,
            maxResponseBytes: self::MAX_TOKEN_RESPONSE,
            destinations: Destinations::originOf($provider->tokenEndpoint),
        ));
        if ($response->status !== 200) {
            throw new OidcSignInFailed(OidcSignInFailed::TOKEN, "{$this->issuer}'s token endpoint answered {$response->status}.");
        }
        try {
            $answer = $response->json();
        } catch (\JsonException) {
            $answer = null;
        }
        $idToken = \is_array($answer) ? ($answer['id_token'] ?? null) : null;
        if (!\is_string($idToken) || $idToken === '') {
            throw new OidcSignInFailed(OidcSignInFailed::TOKEN, "{$this->issuer}'s token endpoint answered with no ID token.");
        }

        return $idToken;
    }

    private function nonce(string $id, #[\SensitiveParameter] string $sessionId): string
    {
        return Signer::encode($this->signer->sign("nonce|{$id}|" . self::sessionHash($sessionId)));
    }

    /** 43 characters, as RFC 7636 has a verifier at least. */
    private function verifier(string $id, #[\SensitiveParameter] string $sessionId): string
    {
        return Signer::encode($this->signer->sign("verifier|{$id}|" . self::sessionHash($sessionId)));
    }

    private static function sessionHash(#[\SensitiveParameter] string $sessionId): string
    {
        return hash('sha256', "oidc-session\0{$sessionId}");
    }

    private static function returnPath(string $path): string
    {
        // A path on this application, never another's: one slash, then not a
        // second or a backslash, and nothing a header could be broken with.
        if (\strlen($path) > self::MAX_RETURN || preg_match('#^/(?![/\\\\])[\x21-\x7e]*\z#', $path) !== 1) {
            return '/';
        }

        return $path;
    }
}
