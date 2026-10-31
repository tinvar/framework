<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Config\Secret;
use Tinvar\Http\NonceStore;
use Tinvar\Http\Response;
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
 * this client as audience, expiry, and the nonce this session sent. The
 * tokens granted come back with the sign-in as Secrets (OidcTokens); the
 * framework keeps none of them (ADR 0124).
 *
 * After the sign-in, with what the application kept of it (ADR 0124):
 *
 *  - userinfo() asks the provider's userinfo endpoint for the person's
 *    claims with the access token, held to that endpoint's origin, and
 *    takes the answer only when its `sub` is the ID token's -- Core 1.0,
 *    5.3.4: an answer about anyone else is a substituted token's;
 *  - refresh() trades a refresh token for new tokens at the token
 *    endpoint, and an ID token sent with them is verified against the
 *    sign-in it renews: same issuer, audience and subject (12.2);
 *  - logoutUrl() is where to send the browser, once the application has
 *    ended its own session, to sign out at the provider too
 *    (RP-Initiated Logout 1.0), coming back to the post-logout URI the
 *    client was made with.
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
        private readonly ?string $postLogoutRedirectUri = null,
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
        foreach (array_filter([$redirectUri, $postLogoutRedirectUri]) as $uri) {
            if (!self::localOrHttps($uri)) {
                throw new \InvalidArgumentException('A redirect URI is https, or plain http on this machine alone.');
            }
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
        $tokens = $this->grant($provider, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $this->verifier($id, $sessionId),
        ], OidcSignInFailed::TOKEN, $now);
        if ($tokens->idToken === null) {
            throw new OidcSignInFailed(OidcSignInFailed::TOKEN, "{$this->issuer}'s token endpoint answered with no ID token.");
        }

        try {
            $verified = $this->verifier->verify(
                $tokens->idToken->reveal(),
                $provider,
                fn (bool $refresh): array => $this->discovery->keys($provider, $refresh),
                $this->clientId,
                $this->nonce($id, $sessionId),
                $now,
            );
        } catch (IdTokenRefused $e) {
            throw new OidcSignInFailed(OidcSignInFailed::ID_TOKEN, "The ID token is refused ({$e->reason}).", $e);
        }

        return new OidcSignIn($verified, \is_string($state['r'] ?? null) ? $state['r'] : '/', $tokens);
    }

    /**
     * The person's claims from the provider's userinfo endpoint, asked
     * with the access token a sign-in or a refresh granted, for the person
     * $idToken names.
     *
     * Only a JSON answer is taken -- a signed or encrypted one is not read
     * here -- and only when its `sub` is the ID token's: an access token
     * for anyone else, put in this one's place, is refused rather than read
     * as them (Core 1.0, 5.3.4).
     *
     * @return array<string, mixed>
     * @throws OidcSignInFailed
     */
    public function userinfo(Secret $accessToken, IdToken $idToken): array
    {
        $provider = $this->discovery->discover($this->issuer);
        if ($provider->userinfoEndpoint === null) {
            throw new OidcSignInFailed(OidcSignInFailed::PROVIDER, "{$this->issuer} names no userinfo endpoint.");
        }
        if ($idToken->issuer !== $this->issuer) {
            throw new OidcSignInFailed(OidcSignInFailed::USERINFO, 'The ID token is another provider\'s.');
        }

        $response = $this->http->send(new OutboundRequest(
            'GET',
            $provider->userinfoEndpoint,
            ['accept' => 'application/json', 'authorization' => 'Bearer ' . $accessToken->reveal()],
            timeout: OidcDiscovery::TIMEOUT * 2,
            maxResponseBytes: self::MAX_TOKEN_RESPONSE,
            destinations: Destinations::originOf($provider->userinfoEndpoint),
        ));
        if ($response->status !== 200) {
            throw new OidcSignInFailed(OidcSignInFailed::USERINFO, "{$this->issuer}'s userinfo endpoint answered {$response->status}.");
        }
        $type = strtolower(trim(explode(';', $response->header('content-type') ?? '')[0]));
        if ($type !== 'application/json') {
            throw new OidcSignInFailed(OidcSignInFailed::USERINFO, "{$this->issuer}'s userinfo is not plain JSON; a signed or encrypted answer is not read here.");
        }
        try {
            $claims = $response->json();
        } catch (\JsonException) {
            $claims = null;
        }
        if (!\is_array($claims) || ($claims !== [] && array_is_list($claims))) {
            throw new OidcSignInFailed(OidcSignInFailed::USERINFO, "{$this->issuer}'s userinfo is not a JSON object.");
        }
        $sub = $claims['sub'] ?? null;
        if (!\is_string($sub) || !hash_equals($idToken->subject, $sub)) {
            throw new OidcSignInFailed(OidcSignInFailed::USERINFO, "{$this->issuer}'s userinfo is about another person than the ID token.");
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * New tokens for the sign-in $signedIn names, for its refresh token.
     *
     * An ID token in the answer is verified against $signedIn -- the same
     * issuer, audience and subject -- and a refresh token in it replaces
     * the one given, which a provider that rotates them has now spent: the
     * application keeps the new one in the old one's place. A refresh that
     * is refused is the provider saying the grant is over, and the
     * application signs the person out.
     *
     * @throws OidcSignInFailed
     */
    public function refresh(Secret $refreshToken, IdToken $signedIn): OidcRefreshed
    {
        if ($signedIn->issuer !== $this->issuer) {
            throw new OidcSignInFailed(OidcSignInFailed::REFRESH, 'The sign-in being renewed was another provider\'s.');
        }
        $now = ($this->clock)();
        $provider = $this->discovery->discover($this->issuer);
        $tokens = $this->grant($provider, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken->reveal(),
        ], OidcSignInFailed::REFRESH, $now);

        $idToken = $signedIn;
        if ($tokens->idToken !== null) {
            try {
                $idToken = $this->verifier->verifyRefreshed(
                    $tokens->idToken->reveal(),
                    $provider,
                    fn (bool $refresh): array => $this->discovery->keys($provider, $refresh),
                    $this->clientId,
                    $signedIn,
                    $now,
                );
            } catch (IdTokenRefused $e) {
                throw new OidcSignInFailed(OidcSignInFailed::REFRESH, "The renewed ID token is refused ({$e->reason}).", $e);
            }
        }

        return new OidcRefreshed($idToken, new OidcTokens(
            $tokens->accessToken,
            $tokens->refreshToken ?? $refreshToken,
            $tokens->idToken,
            $tokens->expiresAt,
        ));
    }

    /**
     * Where to send the browser to sign out at the provider as well
     * (RP-Initiated Logout 1.0), once the application has ended its own
     * session: the provider's end-session endpoint with this client's id,
     * the ID token as a hint where the application kept it, and the
     * post-logout URI the client was made with, to come back to.
     *
     * The application's own session ends first, whatever the provider
     * does: a browser that never reaches the provider, or never comes back,
     * is signed out here all the same.
     *
     * @throws OidcSignInFailed when the provider names no end-session endpoint
     */
    public function logoutUrl(?Secret $idTokenHint = null): string
    {
        $provider = $this->discovery->discover($this->issuer);
        if ($provider->endSessionEndpoint === null) {
            throw new OidcSignInFailed(OidcSignInFailed::PROVIDER, "{$this->issuer} names no end-session endpoint.");
        }

        $query = ['client_id' => $this->clientId];
        if ($idTokenHint !== null) {
            $query['id_token_hint'] = $idTokenHint->reveal();
        }
        if ($this->postLogoutRedirectUri !== null) {
            $query['post_logout_redirect_uri'] = $this->postLogoutRedirectUri;
        }

        return $provider->endSessionEndpoint . (str_contains($provider->endSessionEndpoint, '?') ? '&' : '?')
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The tokens the token endpoint grants for $form: an access token at
     * least, the rest as the provider answers.
     *
     * @param array<string, string> $form
     * @param string $reason the failure's reason, as the caller names it
     */
    private function grant(OidcProvider $provider, #[\SensitiveParameter] array $form, string $reason, int $now): OidcTokens
    {
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
            throw new OidcSignInFailed($reason, "{$this->issuer}'s token endpoint answered {$response->status}.");
        }
        try {
            $answer = $response->json();
        } catch (\JsonException) {
            $answer = null;
        }
        if (!\is_array($answer)) {
            throw new OidcSignInFailed($reason, "{$this->issuer}'s token endpoint did not answer with a JSON object.");
        }

        // A bearer token: RFC 6750's, the only kind a request here carries.
        $access = $answer['access_token'] ?? null;
        $type = $answer['token_type'] ?? null;
        if (!\is_string($access) || $access === '' || !\is_string($type) || strcasecmp($type, 'Bearer') !== 0) {
            throw new OidcSignInFailed($reason, "{$this->issuer}'s token endpoint answered with no bearer access token.");
        }
        $refresh = $answer['refresh_token'] ?? null;
        $idToken = $answer['id_token'] ?? null;
        $expiresIn = $answer['expires_in'] ?? null;

        return new OidcTokens(
            new Secret($access),
            \is_string($refresh) && $refresh !== '' ? new Secret($refresh) : null,
            \is_string($idToken) && $idToken !== '' ? new Secret($idToken) : null,
            \is_int($expiresIn) && $expiresIn > 0 ? $now + $expiresIn : null,
        );
    }

    /** Whether $uri is https, or plain http on this machine alone. */
    private static function localOrHttps(string $uri): bool
    {
        if (str_starts_with($uri, 'https://')) {
            return true;
        }

        // The host whole, not a prefix of it: http://localhost.attacker.example is not this machine.
        $host = parse_url($uri, PHP_URL_HOST);

        return str_starts_with($uri, 'http://') && \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
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

    /**
     * A path on this application to go on to, or `/`: root-relative and
     * same-origin by Response's own rules, as HandoffEndpoint's is, so the
     * two cannot drift -- encoded traversal (`/%2e%2e//host`) included. No
     * fragment, and nothing past the cap.
     */
    private static function returnPath(string $path): string
    {
        if ($path === '' || \strlen($path) > self::MAX_RETURN || str_contains($path, '#')
            || !Response::isSameOriginTarget($path)) {
            return '/';
        }

        return $path;
    }
}
