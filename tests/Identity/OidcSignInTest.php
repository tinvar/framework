<?php

declare(strict_types=1);

/**
 * Signing in through an OpenID provider (ADR 0102): the authorization code
 * flow with PKCE, its state and nonce bound to the session that began it,
 * and the ID token verified -- signature by the discovered key set, exact
 * issuer, audience, expiry, nonce, and an algorithm list with no `none`
 * and no HMAC -- against a provider in this process.
 */

namespace Tinvar\Tests\Identity\OidcSignIn;

use Tinvar\Config\Secret;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Identity\IdTokenRefused;
use Tinvar\Identity\IdTokenVerifier;
use Tinvar\Identity\OidcClient;
use Tinvar\Identity\OidcDiscovery;
use Tinvar\Identity\OidcSignInFailed;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Support\Signer;
use Tinvar\Tests\Identity\Fixtures\FakeOidcProvider;

require_once __DIR__ . '/Fixtures/FakeOidcProvider.php';

const SESSION = 'the-session-that-began-it';
const REDIRECT = 'https://shop.example/sign-in/oidc';

function client(FakeOidcProvider $idp, ?Secret $secret = null, ?IdTokenVerifier $verifier = null): OidcClient
{
    $clock = static fn (): int => $idp->now;

    return new OidcClient(
        new OidcDiscovery($idp->http, $clock),
        $idp->http,
        new Signer('an-application-key-of-at-least-thirty-two-bytes', OidcClient::STATE_PURPOSE),
        new ArrayNonceStore(),
        FakeOidcProvider::ISSUER,
        FakeOidcProvider::CLIENT,
        REDIRECT,
        $secret,
        verifier: $verifier ?? new IdTokenVerifier(),
        clock: $clock,
    );
}

/** Why the sign-in failed, and the ID token's reason under it, if any. */
function failure(\Closure $finish): string
{
    try {
        $finish();
    } catch (OidcSignInFailed $e) {
        $previous = $e->getPrevious();

        return $e->reason . ($previous instanceof IdTokenRefused ? ":{$previous->reason}" : '');
    }

    return 'signed in';
}

it('signs in: a code with PKCE, exchanged for an ID token whose signature, issuer, audience, expiry and nonce hold', function () {
    $idp = new FakeOidcProvider();
    $client = client($idp);

    $url = $client->start(SESSION, returnTo: '/account');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $signIn = $client->finish(SESSION, $idp->authorize($url));

    parse_str($idp->tokenRequests[0]->body, $form);
    expect($url)->toStartWith(FakeOidcProvider::ISSUER . '/authorize?')
        ->and($query)->toMatchArray(['response_type' => 'code', 'client_id' => FakeOidcProvider::CLIENT, 'redirect_uri' => REDIRECT, 'scope' => 'openid email profile', 'code_challenge_method' => 'S256'])
        ->and(\strlen((string) $query['code_challenge']))->toBe(43)
        ->and($signIn->idToken->subject)->toBe('person-42')
        ->and($signIn->idToken->issuer)->toBe(FakeOidcProvider::ISSUER)
        ->and($signIn->idToken->verifiedEmail())->toBe('ada@example.com')
        ->and($signIn->returnTo)->toBe('/account')
        // A public client: its id in the form, no secret anywhere.
        ->and($form['client_id'] ?? null)->toBe(FakeOidcProvider::CLIENT)
        ->and($idp->tokenRequests[0]->headers)->not->toHaveKey('authorization')
        // Held to the token endpoint's origin, every hop.
        ->and($idp->tokenRequests[0]->destinations?->hosts)->toBe(['idp.example']);
});

it('sends a confidential client\'s secret in Basic authentication, form-encoded', function () {
    $idp = new FakeOidcProvider();
    $client = client($idp, new Secret('s3cret:with/odd chars'));

    $client->finish(SESSION, $idp->authorize($client->start(SESSION)));

    parse_str($idp->tokenRequests[0]->body, $form);
    expect($idp->tokenRequests[0]->headers['authorization'])->toBe('Basic ' . base64_encode('shop-client:s3cret%3Awith%2Fodd%20chars'))
        ->and($form)->not->toHaveKey('client_id');
});

describe('the answer', function () {
    it('is taken only by the session that began the sign-in', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $answer = $idp->authorize($client->start(SESSION));

        expect(failure(fn () => $client->finish('another-session', $answer)))->toBe('state')
            ->and(failure(fn () => $client->finish('', $answer)))->toBe('state')
            ->and($idp->tokenRequests)->toBe([])
            // And still by its own, which nothing above spent.
            ->and(failure(fn () => $client->finish(SESSION, $answer)))->toBe('signed in');
    });

    it('is taken once', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $answer = $idp->authorize($client->start(SESSION));

        expect(failure(fn () => $client->finish(SESSION, $answer)))->toBe('signed in')
            ->and(failure(fn () => $client->finish(SESSION, $answer)))->toBe('state');
    });

    it('is refused after ten minutes, with a state tampered with, and naming another issuer', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $answer = $idp->authorize($client->start(SESSION));

        expect(failure(fn () => $client->finish(SESSION, [...$answer, 'state' => $answer['state'] . 'x'])))->toBe('state')
            ->and(failure(fn () => $client->finish(SESSION, [...$answer, 'iss' => 'https://evil.example'])))->toBe('state');

        $idp->now += OidcClient::TTL;
        expect(failure(fn () => $client->finish(SESSION, $answer)))->toBe('state');
    });

    it('is a refusal when the provider answers with an error, once its state holds', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $answer = $idp->authorize($client->start(SESSION));

        expect(failure(fn () => $client->finish(SESSION, ['state' => $answer['state'], 'error' => 'access_denied'])))->toBe('denied')
            ->and(failure(fn () => $client->finish('another', ['state' => $answer['state'], 'error' => 'access_denied'])))->toBe('state');
    });

    it('is refused when the code is not the one the verifier was made for', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $first = $idp->authorize($client->start(SESSION));
        $second = $idp->authorize($client->start(SESSION));

        // The second sign-in's code under the first's state: another verifier.
        expect(failure(fn () => $client->finish(SESSION, [...$first, 'code' => $second['code']])))->toBe('token');
    });
});

it('is not begun with a provider that does not take PKCE', function () {
    $idp = new FakeOidcProvider();
    $idp->document = ['code_challenge_methods_supported' => ['plain']];

    expect(failure(fn () => client($idp)->start(SESSION)))->toBe('provider');
});

it('goes on to a path on this application alone', function (string $returnTo) {
    $idp = new FakeOidcProvider();
    $client = client($idp);

    expect($client->finish(SESSION, $idp->authorize($client->start(SESSION, $returnTo)))->returnTo)->toBe('/');
})->with(['https://evil.example/', '//evil.example', '/\\evil.example', "/x\r\nSet-Cookie: a=b", 'relative']);

describe('the ID token', function () {
    /** @param \Closure(array<string, mixed>, array<string, mixed>): array<int, mixed> $shape */
    $refused = static function (\Closure $shape, ?\Closure $prepare = null): string {
        $idp = new FakeOidcProvider();
        $idp->shape = $shape;
        if ($prepare !== null) {
            $prepare($idp);
        }
        $client = client($idp);

        return failure(fn () => $client->finish(SESSION, $idp->authorize($client->start(SESSION))));
    };

    it('is refused unsigned, as alg none', function () use ($refused) {
        expect($refused(static function (array $header, array $claims): array {
            $token = Signer::encode((string) json_encode(['alg' => 'none'])) . '.' . Signer::encode((string) json_encode($claims)) . '.';

            return [$header, $claims, 'rsa-1', $token];
        }))->toBe('id_token:malformed')
            ->and($refused(static function (array $header, array $claims): array {
                $token = Signer::encode((string) json_encode(['alg' => 'none'])) . '.' . Signer::encode((string) json_encode($claims)) . '.' . Signer::encode('x');

                return [$header, $claims, 'rsa-1', $token];
            }))->toBe('id_token:algorithm');
    });

    // The oldest confusion: HS256 keyed with the provider's public key,
    // which anyone can read from its key set.
    it('is refused as HS256 keyed with the provider\'s public key', function () use ($refused) {
        expect($refused(static function (array $header, array $claims): array {
            $public = (string) openssl_pkey_get_details(FakeOidcProvider::key('rsa-1'))['key'];
            $signing = Signer::encode((string) json_encode(['alg' => 'HS256', 'kid' => 'rsa-1'])) . '.' . Signer::encode((string) json_encode($claims));

            return [$header, $claims, 'rsa-1', $signing . '.' . Signer::encode(hash_hmac('sha256', $signing, $public, true))];
        }, static function (FakeOidcProvider $idp): void {
            // Even where a provider's document offers it.
            $idp->document = ['id_token_signing_alg_values_supported' => ['RS256', 'HS256']];
        }))->toBe('id_token:algorithm');
    });

    it('is refused signed with an algorithm the provider does not offer, or the application does not take', function () use ($refused) {
        expect($refused(static fn (array $header, array $claims): array => [['alg' => 'RS384', 'kid' => 'rsa-1'], $claims]))->toBe('id_token:algorithm');

        $idp = new FakeOidcProvider();
        $client = client($idp, verifier: new IdTokenVerifier(['ES256']));
        expect(failure(fn () => $client->finish(SESSION, $idp->authorize($client->start(SESSION)))))->toBe('id_token:algorithm');
    });

    it('is believed signed with ES256 by the provider\'s EC key', function () use ($refused) {
        expect($refused(static fn (array $header, array $claims): array => [['alg' => 'ES256', 'kid' => 'ec-1'], $claims, 'ec-1']))->toBe('signed in');
    });

    it('is refused signed by a key not the provider\'s, whatever its header names', function () use ($refused) {
        expect($refused(static function (array $header, array $claims): array {
            // Signed by a key of the attacker's, which the header offers.
            return [['alg' => 'RS256', 'kid' => 'rsa-1', 'jwk' => FakeOidcProvider::jwk('rsa-attacker')], $claims, 'rsa-attacker'];
        }))->toBe('id_token:signature')
            ->and($refused(static fn (array $header, array $claims): array => [['alg' => 'RS256', 'kid' => 'rsa-attacker', 'jku' => 'https://evil.example/jwks'], $claims, 'rsa-attacker']))->toBe('id_token:key');
    });

    it('is refused with its claims changed after signing', function () use ($refused) {
        expect($refused(static function (array $header, array $claims): array {
            [$h, , $s] = explode('.', FakeOidcProvider::sign($header, $claims, 'rsa-1'));

            return [$header, $claims, 'rsa-1', $h . '.' . Signer::encode((string) json_encode([...$claims, 'sub' => 'someone-else'])) . '.' . $s];
        }))->toBe('id_token:signature');
    });

    it('is believed after the provider rotates to a key not yet fetched, asking for the set once more', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $client->finish(SESSION, $idp->authorize($client->start(SESSION)));

        $idp->published = ['rsa-1', 'rsa-2'];
        $idp->now += OidcDiscovery::REFRESH_INTERVAL + 1;
        $idp->shape = static fn (array $header, array $claims): array => [[...$header, 'kid' => 'rsa-2'], [...$claims, 'exp' => $claims['exp'], 'iat' => $claims['iat']], 'rsa-2'];

        expect(failure(fn () => $client->finish(SESSION, $idp->authorize($client->start(SESSION)))))->toBe('signed in');
    });

    it('is refused signed by an RSA key under 2048 bits', function () use ($refused) {
        expect($refused(
            static fn (array $header, array $claims): array => [['alg' => 'RS256', 'kid' => 'weak-1'], $claims, 'weak-1'],
            static function (FakeOidcProvider $idp): void {
                $idp->published = ['weak-1'];
            },
        ))->toBe('id_token:key');
    });

    it('is refused naming another issuer, another audience, or another party', function (array $changed, string $why) use ($refused) {
        expect($refused(static fn (array $header, array $claims): array => [$header, [...$claims, ...$changed]]))->toBe($why);
    })->with([
        'another issuer' => [['iss' => 'https://idp.example/'], 'id_token:issuer'],
        'another audience' => [['aud' => 'someone-else'], 'id_token:audience'],
        'several, with no azp' => [['aud' => [FakeOidcProvider::CLIENT, 'someone-else']], 'id_token:audience'],
        'several, azp another' => [['aud' => [FakeOidcProvider::CLIENT, 'someone-else'], 'azp' => 'someone-else'], 'id_token:audience'],
        'azp another' => [['azp' => 'someone-else'], 'id_token:audience'],
        'no subject' => [['sub' => ''], 'id_token:subject'],
    ]);

    it('is believed for several audiences when azp names this client', function () use ($refused) {
        expect($refused(static fn (array $header, array $claims): array => [$header, [...$claims, 'aud' => [FakeOidcProvider::CLIENT, 'api'], 'azp' => FakeOidcProvider::CLIENT]]))->toBe('signed in');
    });

    it('is refused expired, issued in the future, not yet valid, or with no times', function () use ($refused) {
        $now = 1_800_000_000;
        expect($refused(static fn (array $h, array $c): array => [$h, [...$c, 'exp' => $now - IdTokenVerifier::LEEWAY]]))->toBe('id_token:expired')
            ->and($refused(static fn (array $h, array $c): array => [$h, [...$c, 'exp' => (string) ($now + 60)]]))->toBe('id_token:expired')
            ->and($refused(static fn (array $h, array $c): array => [$h, [...$c, 'iat' => $now + IdTokenVerifier::LEEWAY + 1]]))->toBe('id_token:not_yet_valid')
            ->and($refused(static fn (array $h, array $c): array => [$h, [...$c, 'nbf' => $now + IdTokenVerifier::LEEWAY + 1]]))->toBe('id_token:not_yet_valid')
            ->and($refused(static fn (array $h, array $c): array => [$h, array_diff_key($c, ['iat' => true])]))->toBe('id_token:not_yet_valid')
            // Within the leeway, either way.
            ->and($refused(static fn (array $h, array $c): array => [$h, [...$c, 'exp' => $now - 30, 'iat' => $now + 30]]))->toBe('signed in');
    });

    it('is refused carrying another sign-in\'s nonce, or none', function () use ($refused) {
        expect($refused(static fn (array $h, array $c): array => [$h, [...$c, 'nonce' => 'another']]))->toBe('id_token:nonce')
            ->and($refused(static fn (array $h, array $c): array => [$h, array_diff_key($c, ['nonce' => true])]))->toBe('id_token:nonce');
    });

    it('is believed signed with PS256 by the provider\'s RSA key, and EdDSA by its Ed25519 key', function (string $alg, string $kid) use ($refused) {
        expect($refused(
            static fn (array $header, array $claims): array => [['alg' => $alg, 'kid' => $kid], $claims, $kid],
            static function (FakeOidcProvider $idp) use ($alg): void {
                $idp->document = ['id_token_signing_alg_values_supported' => ['RS256', $alg]];
                $idp->published = ['rsa-1', 'ed-1'];
            },
        ))->toBe('signed in');
    })->with([
        'PS256' => ['PS256', 'rsa-1'],
        'PS384' => ['PS384', 'rsa-1'],
        'PS512' => ['PS512', 'rsa-1'],
        'EdDSA' => ['EdDSA', 'ed-1'],
        'Ed25519' => ['Ed25519', 'ed-1'],
    ]);

    // One RSA key verifies both paddings, so the header's alg must be the
    // padding the signature was made with: a PKCS#1 v1.5 signature under a
    // PS256 header, or the other way about, is not believed.
    it('is refused with a padding other than its algorithm names', function () use ($refused) {
        $both = static function (FakeOidcProvider $idp): void {
            $idp->document = ['id_token_signing_alg_values_supported' => ['RS256', 'PS256']];
        };
        $swapped = static fn (string $signedAs, string $claimed): \Closure => static function (array $header, array $claims) use ($signedAs, $claimed): array {
            [, $body, $signature] = explode('.', FakeOidcProvider::sign(['alg' => $signedAs, 'kid' => 'rsa-1'], $claims, 'rsa-1'));

            return [$header, $claims, 'rsa-1', Signer::encode((string) json_encode(['alg' => $claimed, 'kid' => 'rsa-1'])) . ".{$body}.{$signature}"];
        };

        expect($refused($swapped('RS256', 'PS256'), $both))->toBe('id_token:signature')
            ->and($refused($swapped('PS256', 'RS256'), $both))->toBe('id_token:signature');
    });

    it('is refused as EdDSA by a key that is not Ed25519, or a key whose alg names another', function () use ($refused) {
        $offers = static function (FakeOidcProvider $idp): void {
            $idp->document = ['id_token_signing_alg_values_supported' => ['RS256', 'EdDSA']];
            $idp->published = ['rsa-1', 'ed-1'];
        };
        // An RSA key named as the EdDSA key: not of the algorithm's type.
        expect($refused(static fn (array $h, array $c): array => [['alg' => 'EdDSA', 'kid' => 'rsa-1'], $c, 'ed-1'], $offers))->toBe('id_token:key')
            // RS256 signed by an Ed25519 key, which an RS256 header cannot name.
            ->and($refused(static fn (array $h, array $c): array => [['alg' => 'RS256', 'kid' => 'ed-1'], $c, 'rsa-1'], $offers))->toBe('id_token:key');
    });

    it('is refused malformed, critical, or larger than one is', function () use ($refused) {
        expect($refused(static fn (array $h, array $c): array => [$h, $c, 'rsa-1', 'a.b']))->toBe('id_token:malformed')
            ->and($refused(static fn (array $h, array $c): array => [[...$h, 'crit' => ['exp']], $c]))->toBe('id_token:malformed')
            ->and($refused(static fn (array $h, array $c): array => [$h, [...$c, 'pad' => str_repeat('x', IdTokenVerifier::MAX_BYTES)]]))->toBe('id_token:malformed');
    });
});

it('sends the code nowhere but the token endpoint\'s origin', function () {
    $idp = new FakeOidcProvider();
    $idp->document = ['token_endpoint' => 'https://idp.example/token'];
    $client = client($idp);
    $client->finish(SESSION, $idp->authorize($client->start(SESSION)));

    $request = $idp->tokenRequests[0];
    expect(fn () => $idp->http->send(new \Tinvar\Outbound\OutboundRequest('POST', 'https://elsewhere.example/token', destinations: $request->destinations)))
        ->toThrow(OutboundRefused::class);
});

it('hands back the tokens granted, as secrets nothing dumps', function () {
    $idp = new FakeOidcProvider();
    $client = client($idp);
    $signIn = $client->finish(SESSION, $idp->authorize($client->start(SESSION)));

    expect($idp->accessTokens)->toHaveKey($signIn->tokens->accessToken->reveal())
        ->and($signIn->tokens->refreshToken?->reveal())->toStartWith('rt-')
        ->and($signIn->tokens->expiresAt)->toBe($idp->now + 300)
        ->and(print_r($signIn, true))->not->toContain($signIn->tokens->accessToken->reveal())
        ->and((string) json_encode($signIn))->not->toContain('rt-');
});

it('refuses a redirect URI on plain http anywhere but this machine', function (string $uri) {
    expect(fn () => new OidcClient(
        new OidcDiscovery(new FakeOidcProvider()->http),
        new FakeOidcProvider()->http,
        new Signer('an-application-key-of-at-least-thirty-two-bytes', OidcClient::STATE_PURPOSE),
        new ArrayNonceStore(),
        FakeOidcProvider::ISSUER,
        FakeOidcProvider::CLIENT,
        $uri,
    ))->toThrow(\InvalidArgumentException::class);
})->with(['http://localhost.evil.example/cb', 'http://127.0.0.1.evil.example/cb', 'http://shop.example/cb']);

describe('userinfo (ADR 0124)', function () {
    it('answers the person\'s claims for their access token, held to the endpoint\'s origin', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $signIn = $client->finish(SESSION, $idp->authorize($client->start(SESSION)));

        $claims = $client->userinfo($signIn->tokens->accessToken, $signIn->idToken);

        $request = $idp->userinfoRequests[0];
        expect($claims)->toMatchArray(['sub' => 'person-42', 'email' => 'ada@example.com'])
            ->and($request->headers['authorization'])->toBe('Bearer ' . $signIn->tokens->accessToken->reveal())
            ->and(fn () => $idp->http->send(new \Tinvar\Outbound\OutboundRequest('GET', 'https://elsewhere.example/userinfo', destinations: $request->destinations)))
            ->toThrow(OutboundRefused::class);
    });

    // Core 1.0, 5.3.4: an answer for anyone but the ID token's subject is
    // a substituted access token's.
    it('is refused about another person than the ID token names', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $signIn = $client->finish(SESSION, $idp->authorize($client->start(SESSION)));
        $idp->userinfo = ['sub' => 'person-7'];

        expect(failure(fn () => $client->userinfo($signIn->tokens->accessToken, $signIn->idToken)))->toBe('userinfo');
    });

    it('is refused signed, rejected, or for a provider with no endpoint', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $signIn = $client->finish(SESSION, $idp->authorize($client->start(SESSION)));

        $idp->userinfoType = 'application/jwt';
        expect(failure(fn () => $client->userinfo($signIn->tokens->accessToken, $signIn->idToken)))->toBe('userinfo');
        $idp->userinfoType = 'application/json';
        expect(failure(fn () => $client->userinfo(new Secret('not-a-token'), $signIn->idToken)))->toBe('userinfo');

        $bare = new FakeOidcProvider();
        $bare->document = ['userinfo_endpoint' => null];
        $other = client($bare);
        expect(failure(fn () => $other->userinfo($signIn->tokens->accessToken, $signIn->idToken)))->toBe('provider');
    });
});

describe('a refresh (ADR 0124)', function () {
    it('trades the refresh token for new ones, the ID token verified as the same person\'s', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $signIn = $client->finish(SESSION, $idp->authorize($client->start(SESSION)));
        $idp->now += 600;

        $refreshed = $client->refresh($signIn->tokens->refreshToken ?? throw new \LogicException(), $signIn->idToken);

        parse_str(end($idp->tokenRequests)->body, $form);
        expect($refreshed->idToken->subject)->toBe('person-42')
            ->and($refreshed->idToken->claims['iat'])->toBe($idp->now)
            ->and($refreshed->tokens->accessToken->reveal())->not->toBe($signIn->tokens->accessToken->reveal())
            // Rotated: the new one replaces the spent one.
            ->and($refreshed->tokens->refreshToken?->reveal())->not->toBe($signIn->tokens->refreshToken->reveal())
            ->and($form)->toMatchArray(['grant_type' => 'refresh_token', 'client_id' => FakeOidcProvider::CLIENT])
            // Spent: the provider takes it once.
            ->and(failure(fn () => $client->refresh($signIn->tokens->refreshToken, $signIn->idToken)))->toBe('refresh');
    });

    it('keeps the sign-in\'s person, and the refresh token, where the provider answers with neither', function () {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $signIn = $client->finish(SESSION, $idp->authorize($client->start(SESSION)));
        $idp->refreshIdToken = false;

        $refreshed = $client->refresh($signIn->tokens->refreshToken ?? throw new \LogicException(), $signIn->idToken);

        expect($refreshed->idToken)->toBe($signIn->idToken)
            ->and($refreshed->tokens->idToken)->toBeNull();
    });

    it('is refused when the renewed ID token names another person, issuer, audience or sign-in', function (array $changed, string $why) {
        $idp = new FakeOidcProvider();
        $client = client($idp);
        $signIn = $client->finish(SESSION, $idp->authorize($client->start(SESSION)));
        $idp->refreshShape = static fn (array $header, array $claims): array => [$header, [...$claims, ...$changed]];

        expect(failure(fn () => $client->refresh($signIn->tokens->refreshToken ?? throw new \LogicException(), $signIn->idToken)))->toBe($why);
    })->with([
        'another subject' => [['sub' => 'person-7'], 'refresh:subject'],
        'another issuer' => [['iss' => 'https://idp.example/'], 'refresh:issuer'],
        'another audience' => [['aud' => 'someone-else'], 'refresh:audience'],
        'another sign-in\'s nonce' => [['nonce' => 'another'], 'refresh:nonce'],
        'expired' => [['exp' => 1_700_000_000], 'refresh:expired'],
    ]);

    it('is refused saying the person signed in at another time than the sign-in did', function () {
        $idp = new FakeOidcProvider();
        $idp->shape = static fn (array $header, array $claims): array => [$header, [...$claims, 'auth_time' => $claims['iat'] - 10]];
        $client = client($idp);
        $signIn = $client->finish(SESSION, $idp->authorize($client->start(SESSION)));
        $idp->refreshShape = static fn (array $header, array $claims): array => [$header, [...$claims, 'auth_time' => $claims['iat']]];

        expect(failure(fn () => $client->refresh($signIn->tokens->refreshToken ?? throw new \LogicException(), $signIn->idToken)))->toBe('refresh:subject');
    });

    it('is refused as HS256, however the provider\'s document reads', function () {
        $idp = new FakeOidcProvider();
        $idp->document = ['id_token_signing_alg_values_supported' => ['RS256', 'HS256']];
        $client = client($idp);
        $signIn = $client->finish(SESSION, $idp->authorize($client->start(SESSION)));
        $idp->refreshShape = static function (array $header, array $claims): array {
            $public = (string) openssl_pkey_get_details(FakeOidcProvider::key('rsa-1'))['key'];
            $signing = Signer::encode((string) json_encode(['alg' => 'HS256', 'kid' => 'rsa-1'])) . '.' . Signer::encode((string) json_encode([...$claims, 'sub' => 'person-42']));

            return [$header, $claims, 'rsa-1', $signing . '.' . Signer::encode(hash_hmac('sha256', $signing, $public, true))];
        };

        expect(failure(fn () => $client->refresh($signIn->tokens->refreshToken ?? throw new \LogicException(), $signIn->idToken)))->toBe('refresh:algorithm');
    });
});

describe('logout (ADR 0124)', function () {
    it('sends the browser to the end-session endpoint with the hint, the client and the way back', function () {
        $idp = new FakeOidcProvider();
        $client = new OidcClient(
            new OidcDiscovery($idp->http),
            $idp->http,
            new Signer('an-application-key-of-at-least-thirty-two-bytes', OidcClient::STATE_PURPOSE),
            new ArrayNonceStore(),
            FakeOidcProvider::ISSUER,
            FakeOidcProvider::CLIENT,
            REDIRECT,
            postLogoutRedirectUri: 'https://shop.example/signed-out',
        );

        $url = $client->logoutUrl(new Secret('the.id.token'));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toStartWith(FakeOidcProvider::ISSUER . '/logout?')
            ->and($query)->toBe(['client_id' => FakeOidcProvider::CLIENT, 'id_token_hint' => 'the.id.token', 'post_logout_redirect_uri' => 'https://shop.example/signed-out'])
            ->and(fn () => new OidcClient(
                new OidcDiscovery($idp->http),
                $idp->http,
                new Signer('an-application-key-of-at-least-thirty-two-bytes', OidcClient::STATE_PURPOSE),
                new ArrayNonceStore(),
                FakeOidcProvider::ISSUER,
                FakeOidcProvider::CLIENT,
                REDIRECT,
                postLogoutRedirectUri: 'http://shop.example/signed-out',
            ))->toThrow(\InvalidArgumentException::class);
    });

    it('is refused for a provider that names no end-session endpoint', function () {
        $idp = new FakeOidcProvider();
        $idp->document = ['end_session_endpoint' => null];

        expect(failure(fn () => client($idp)->logoutUrl()))->toBe('provider');
    });
});
