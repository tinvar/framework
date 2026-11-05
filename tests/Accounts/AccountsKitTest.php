<?php

declare(strict_types=1);

/**
 * The accounts kit's flows (ADR 0153) over an application's own users table
 * -- `members`, through the AccountUsers contract -- on SQLite and, where
 * one is there, Postgres: sessions with rows and their realms, the second
 * factor, passkeys end to end with a software authenticator, emailed links
 * and what the queue worker sends.
 *
 * The whole request path -- middleware, CSRF, actions -- is the skeleton's
 * own suite (packages/skeleton/tests/Feature/AccountsTest.php) and its
 * browser test; this holds the kit to its rules with nothing in between.
 */

namespace Tinvar\Tests\Accounts\Kit;

use Carbon\CarbonImmutable;
use Tinvar\Accounts\AccountLinks;
use Tinvar\Accounts\AccountPaths;
use Tinvar\Accounts\Accounts;
use Tinvar\Accounts\AccountsKit;
use Tinvar\Accounts\Passkeys\Base64Url;
use Tinvar\Accounts\Passwords;
use Tinvar\Accounts\RequireSignIn;
use Tinvar\Accounts\SessionState;
use Tinvar\Accounts\Sessions;
use Tinvar\Accounts\SignInResult;
use Tinvar\Accounts\TwoFactor\Base32;
use Tinvar\Accounts\TwoFactor\Totp;
use Tinvar\Api\ApiTokens;
use Tinvar\Api\ArrayApiTokens;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\Arrival;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Tests\Accounts\Fixtures\MemberApp;
use Tinvar\Tests\Accounts\Fixtures\Members;
use Tinvar\Tests\Accounts\Fixtures\SoftwareAuthenticator;
use Tinvar\Tests\Support\Fixtures\Databases;
use Tinvar\Validation\ValidationException;

require_once __DIR__ . '/Fixtures/SoftwareAuthenticator.php';
require_once __DIR__ . '/Fixtures/Members.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';

const PASSWORD = 'correct horse battery staple';

/** A browser of one: its session cookie, the host it talks to, and the kit. */
final class Visitor
{
    public string $cookie;

    public function __construct(public readonly MemberApp $app, public string $host = 'app.example', public string $address = '203.0.113.5')
    {
        $this->cookie = $app->kit->cookie->issue();
    }

    /**
     * Runs $work as one request would, with the session opened as the
     * middleware opens it, and keeps the cookie a rotation set.
     *
     * @template T
     * @param \Closure(Accounts, Request): T $work
     * @return T
     */
    public function do(\Closure $work): mixed
    {
        $request = $this->request();
        $state = $this->app->kit->sessions->open($request);
        // Signed in for the request as StartSession signs it in.
        $this->app->auth->login($state->user(), $state->epoch());
        try {
            return $work($this->app->kit->accounts, $request->withAttribute(SessionState::ATTRIBUTE, $state));
        } finally {
            $this->cookie = $state->replacement() ?? $this->cookie;
            $this->app->auth->login(null);
        }
    }

    /** Types the password again, as the account page asks before a change to how it signs in. */
    public function confirm(string $password = PASSWORD): void
    {
        $this->do(static function (Accounts $a, Request $r) use ($password): void {
            $user = SessionState::of($r)->user() ?? throw new \LogicException('Nobody is signed in to confirm.');
            $a->confirmWithPassword($r, $user, $password);
        });
    }

    public function state(): SessionState
    {
        return $this->app->kit->sessions->open($this->request());
    }

    public function who(): ?string
    {
        return $this->state()->user()?->accountEmail();
    }

    private function request(): Request
    {
        return new Request('POST', '/_action/x', ['host' => $this->host], cookies: [$this->app->kit->cookie->name => $this->cookie], remoteAddress: $this->address);
    }
}

/** @param \Closure(MemberApp): void $test */
function onDriver(string $driver, \Closure $test): void
{
    $run = static function (PdoConnection $connection) use ($test): void {
        $schema = new Schema($connection, Dialect::for($connection));
        Members::define($schema);
        AccountsKit::migrate($schema, \Tinvar\Tests\Accounts\Fixtures\Member::class);
        $test(new MemberApp($connection));
    };
    if ($driver === 'sqlite') {
        $run(PdoConnection::sqlite(':memory:', 'accounts'));

        return;
    }
    $missing = Databases::postgresMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    \Tinvar\Tests\Orm\Fixtures\Databases::inScratchSchema('pgsql', static fn (\PDO $pdo): mixed => $run(new PdoConnection($pdo, 'accounts')));
}

/** Signs $email up through the link, and returns the browser, signed in. */
function member(MemberApp $app, string $email = 'ada@example.com'): Visitor
{
    $visitor = new Visitor($app);
    $visitor->do(static fn (Accounts $a, Request $r) => $a->requestSignUp($r, $email, 'Ada'));
    $app->work();
    $url = $app->links('sign-up', $email)[0] ?? throw new \LogicException('No sign-up link was sent.');
    $visitor->do(static fn (Accounts $a, Request $r) => $a->completeSignUp($r, token($url), PASSWORD));

    return $visitor;
}

function token(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query[EmailLinks::PARAMETER];
}

/** A credential as the browser's toJSON() writes it. */
function credential(SoftwareAuthenticator $authenticator, array $response, ?string $userHandle = null): string
{
    $encoded = [];
    foreach ($response as $field => $bytes) {
        $name = ['clientDataJson' => 'clientDataJSON', 'authData' => null][$field] ?? $field;
        if ($name !== null) {
            $encoded[$name] = Base64Url::encode($bytes);
        }
    }
    if ($userHandle !== null) {
        $encoded['userHandle'] = Base64Url::encode($userHandle);
    }
    $id = Base64Url::encode($authenticator->credentialId);

    return json_encode(['id' => $id, 'rawId' => $id, 'type' => 'public-key', 'response' => $encoded], JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function refused(\Closure $work): array
{
    try {
        $work();
    } catch (ValidationException $e) {
        return $e->errors;
    }

    throw new \LogicException('It was not refused.');
}

dataset('drivers', ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql']]);

it('makes an account only from a link to its address, and signs that browser in', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $visitor = member($app, 'ada@example.com');
        expect($visitor->who())->toBe('ada@example.com')
            ->and($app->orm->connection()->select('SELECT COUNT(*) AS n FROM members')[0]['n'])->toBe(1);

        // Again for the same address: the owner is told, and no second account.
        $other = new Visitor($app);
        $other->do(static fn (Accounts $a, Request $r) => $a->requestSignUp($r, 'Ada@Example.com', 'Impostor'));
        $app->work();
        expect($app->links('already-registered', 'ada@example.com'))->toHaveCount(1)
            ->and($app->links('sign-up', 'ada@example.com'))->toHaveCount(1);
    });
})->with('drivers');

it('rotates the session on sign-in, binds it to its host, and ends it everywhere', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        member($app);
        $laptop = new Visitor($app);
        $before = $laptop->cookie;
        expect($laptop->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD)))->toBe(SignInResult::SignedIn)
            ->and($laptop->cookie)->not->toBe($before)
            ->and($laptop->who())->toBe('ada@example.com');

        // The same cookie on another host signs nobody in there.
        $elsewhere = clone $laptop;
        $elsewhere->host = 'evil.example';
        expect($elsewhere->who())->toBeNull();

        $phone = new Visitor($app);
        $phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $epoch = $phone->state()->epoch();
        $laptop->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r));
        expect($phone->who())->toBeNull()
            ->and($laptop->who())->toBeNull()
            ->and($app->kit->epochs->of((string) $app->orm->connection()->select('SELECT id FROM members')[0]['id']))->not->toBe($epoch);
    });
})->with('drivers');

it('holds a sign-in at the second factor, and takes each code once', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $owner->confirm();
        $setup = $owner->do(static fn (Accounts $a, Request $r) => $a->beginTwoFactor($r, $user));
        $secret = (string) Base32::decode($setup['secret']);
        expect($setup['uri'])->toStartWith('otpauth://totp/Test%20app:ada%40example.com?secret=');

        expect(refused(static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->confirmTwoFactor($r, $user, '000000'))))->toHaveKey('code');
        $codes = $owner->do(static fn (Accounts $a, Request $r) => $a->confirmTwoFactor($r, $user, Totp::at($secret, $app->now)));
        expect($codes)->toHaveCount(10);

        $phone = new Visitor($app);
        expect($phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD)))->toBe(SignInResult::SecondFactor)
            ->and($phone->who())->toBeNull()
            ->and($phone->state()->pending()?->accountEmail())->toBe('ada@example.com');

        // The step that confirmed is spent; the next one is not.
        expect(refused(static fn () => $phone->do(static fn (Accounts $a, Request $r) => $a->completeSecondFactor($r, Totp::at($secret, $app->now)))))->toHaveKey('code');
        $app->now += Totp::PERIOD;
        $phone->do(static fn (Accounts $a, Request $r) => $a->completeSecondFactor($r, Totp::at($secret, $app->now)));
        expect($phone->who())->toBe('ada@example.com');

        // A waiting sign-in lapses after ten minutes.
        $late = new Visitor($app);
        $late->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $app->now += 601;
        expect($late->state()->pending())->toBeNull()
            ->and(refused(static fn () => $late->do(static fn (Accounts $a, Request $r) => $a->completeSecondFactor($r, $codes[0]))))->toBe(['code' => [Accounts::SECOND_FACTOR_EXPIRED]]);
    });
})->with('drivers');

it('counts second-factor guesses per account, five to a window', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $owner->confirm();
        $secret = (string) Base32::decode($owner->do(static fn (Accounts $a, Request $r) => $a->beginTwoFactor($r, $user))['secret']);
        $owner->do(static fn (Accounts $a, Request $r) => $a->confirmTwoFactor($r, $user, Totp::at($secret, $app->now)));

        $guesser = new Visitor($app, address: '198.51.100.3');
        $guesser->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        for ($i = 0; $i < 5; $i++) {
            refused(static fn () => $guesser->do(static fn (Accounts $a, Request $r) => $a->completeSecondFactor($r, sprintf('%06d', $i))));
        }
        expect(static fn () => $guesser->do(static fn (Accounts $a, Request $r) => $a->completeSecondFactor($r, '123456')))->toThrow(HttpException::class);
    });
});

it('adds a passkey and signs in with it, for this host and this session alone', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $authenticator = SoftwareAuthenticator::es256();

        $owner->confirm();
        $options = $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user));
        expect($options['rp'])->toBe(['id' => 'app.example', 'name' => 'Test app'])
            ->and($options['authenticatorSelection']['userVerification'])->toBe('required');
        $challenge = (string) Base64Url::decode($options['challenge']);
        $handle = (string) Base64Url::decode($options['user']['id']);
        $made = $authenticator->register($challenge);
        $owner->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($authenticator, $made), 'Laptop'));
        expect($app->kit->accounts->passkeys($user))->toHaveCount(1);

        // The challenge was spent with it: the same answer again finds none.
        expect(refused(static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($authenticator, $made), 'Again'))))->toHaveKey('credential');

        $visitor = new Visitor($app);
        $signIn = $visitor->do(static fn (Accounts $a, Request $r) => $a->passkeySignInOptions($r));
        expect($signIn['rpId'])->toBe('app.example')->and($signIn['allowCredentials'])->toBe([]);
        $answer = $authenticator->assert((string) Base64Url::decode($signIn['challenge']));

        // Another session cannot use this session's challenge.
        $thief = new Visitor($app);
        expect(refused(static fn () => $thief->do(static fn (Accounts $a, Request $r) => $a->signInWithPasskey($r, credential($authenticator, $answer, $handle)))))->toHaveKey('credential');

        $visitor->do(static fn (Accounts $a, Request $r) => $a->signInWithPasskey($r, credential($authenticator, $answer, $handle)));
        expect($visitor->who())->toBe('ada@example.com');

        // A replay of the same answer, with a fresh challenge it did not sign, is refused.
        $replay = new Visitor($app);
        $replay->do(static fn (Accounts $a, Request $r) => $a->passkeySignInOptions($r));
        expect(refused(static fn () => $replay->do(static fn (Accounts $a, Request $r) => $a->signInWithPasskey($r, credential($authenticator, $answer, $handle)))))->toHaveKey('credential');

        // A counter that goes back says the key was copied.
        $cloned = new Visitor($app);
        $c = (string) Base64Url::decode($cloned->do(static fn (Accounts $a, Request $r) => $a->passkeySignInOptions($r))['challenge']);
        $back = $authenticator->assert($c, ['signCount' => 1]);
        expect(refused(static fn () => $cloned->do(static fn (Accounts $a, Request $r) => $a->signInWithPasskey($r, credential($authenticator, $back, $handle)))))->toHaveKey('credential');

        // A user handle that is not the passkey's account's is refused.
        $wrong = new Visitor($app);
        $c = (string) Base64Url::decode($wrong->do(static fn (Accounts $a, Request $r) => $a->passkeySignInOptions($r))['challenge']);
        $forged = $authenticator->assert($c);
        expect(refused(static fn () => $wrong->do(static fn (Accounts $a, Request $r) => $a->signInWithPasskey($r, credential($authenticator, $forged, random_bytes(32))))))->toHaveKey('credential');

        // On another host, no ceremony starts.
        $other = new Visitor($app, 'other.example');
        expect(refused(static fn () => $other->do(static fn (Accounts $a, Request $r) => $a->passkeySignInOptions($r))))->toBe(['credential' => [Accounts::WRONG_HOST]]);
    });
})->with('drivers');

it('keeps a session\'s new challenge when an old one of its own for another ceremony has expired', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $authenticator = SoftwareAuthenticator::es256();

        // The "confirm it's you" dialog offered a passkey, and was left open.
        $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyConfirmationOptions($r, $user));
        $app->now += 400;

        // The owner types the password instead, then adds a passkey.
        $owner->confirm();
        $options = $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user));
        $made = $authenticator->register((string) Base64Url::decode($options['challenge']));
        $owner->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($authenticator, $made), 'Laptop'));

        expect($app->kit->accounts->passkeys($user))->toHaveCount(1);
    });
})->with('drivers');

it('removes a passkey for its own account alone', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $ada = member($app, 'ada@example.com');
        $bob = member($app, 'bob@example.com');
        $adaUser = $ada->state()->user();
        $bobUser = $bob->state()->user();
        \assert($adaUser !== null && $bobUser !== null);
        $authenticator = SoftwareAuthenticator::rs256();
        $ada->confirm();
        $options = $ada->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $adaUser));
        $passkey = $ada->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $adaUser, credential($authenticator, $authenticator->register((string) Base64Url::decode($options['challenge']))), 'Key'));

        $bob->confirm();
        expect($bob->do(static fn (Accounts $a, Request $r) => $a->removePasskey($r, $bobUser, $passkey->id)))->toBeFalse()
            ->and($ada->do(static fn (Accounts $a, Request $r) => $a->removePasskey($r, $adaUser, $passkey->id)))->toBeTrue()
            ->and($app->kit->accounts->passkeys($adaUser))->toBe([]);
    });
});

it('asks a session to confirm it is its owner before it removes a passkey, and tells the owner', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $key = SoftwareAuthenticator::es256();
        $owner->confirm();
        $options = $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user));
        $passkey = $owner->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($key, $key->register((string) Base64Url::decode($options['challenge']))), 'Laptop'));

        // A stolen session cookie, its owner's confirmation long lapsed: the passkey stays.
        $app->now += Accounts::CONFIRMATION_LIFETIME;
        expect(refused(static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->removePasskey($r, $user, $passkey->id))))->toBe(['confirm' => [Accounts::CONFIRM_FIRST]])
            ->and($app->kit->accounts->passkeys($user))->toHaveCount(1);

        $owner->confirm();
        expect($owner->do(static fn (Accounts $a, Request $r) => $a->removePasskey($r, $user, $passkey->id)))->toBeTrue()
            ->and($app->kit->accounts->passkeys($user))->toBe([]);
        $app->work();
        expect($app->links('passkey-removed', 'ada@example.com'))->toBe(['https://app.example/account']);
    });
});

it('ends every other session and token when a passkey is removed, as a change of password does', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $id = $user->authIdentifier();
        $key = SoftwareAuthenticator::es256();
        $owner->confirm();
        $options = $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user));
        $passkey = $owner->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($key, $key->register((string) Base64Url::decode($options['challenge']))), 'Laptop'));

        // A session signed in with the passkey -- the one a thief who took the key holds, say.
        $other = new Visitor($app);
        $options = $other->do(static fn (Accounts $a, Request $r) => $a->passkeySignInOptions($r));
        $other->do(static fn (Accounts $a, Request $r) => $a->signInWithPasskey($r, credential($key, $key->assert((string) Base64Url::decode($options['challenge'])), $passkey->userHandle)));
        expect($other->who())->toBe('ada@example.com');
        $epoch = $app->kit->epochs->of($id);
        $credential = $app->kit->credentials->of($id);

        expect($owner->do(static fn (Accounts $a, Request $r) => $a->removePasskey($r, $user, $passkey->id)))->toBeTrue();

        expect($other->who())->toBeNull()
            ->and($owner->who())->toBe('ada@example.com')
            ->and($app->kit->epochs->of($id))->not->toBe($epoch)
            ->and($app->kit->credentials->of($id))->not->toBe($credential);
    });
})->with('drivers');

it('asks a session to confirm it is its owner before it adds a passkey or turns on two-factor', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        // A stolen session cookie is all the thief has: no password, no passkey.
        $thief = member($app);
        $user = $thief->state()->user();
        \assert($user !== null);
        $authenticator = SoftwareAuthenticator::es256();
        $confirmFirst = ['confirm' => [Accounts::CONFIRM_FIRST]];

        expect(refused(static fn () => $thief->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user))))->toBe($confirmFirst)
            ->and(refused(static fn () => $thief->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($authenticator, $authenticator->register(random_bytes(32))), 'Mine now'))))->toBe($confirmFirst)
            ->and(refused(static fn () => $thief->do(static fn (Accounts $a, Request $r) => $a->beginTwoFactor($r, $user))))->toBe($confirmFirst)
            ->and(refused(static fn () => $thief->do(static fn (Accounts $a, Request $r) => $a->confirmTwoFactor($r, $user, '123456'))))->toBe($confirmFirst)
            ->and($app->kit->accounts->passkeys($user))->toBe([])
            ->and($thief->do(static fn (Accounts $a, Request $r) => $a->recentlyConfirmed($r)))->toBeFalse();

        // A wrong password is a guess, counted, and confirms nothing.
        expect(refused(static fn () => $thief->confirm('not the password')))->toBe(['currentPassword' => [Accounts::WRONG_PASSWORD]])
            ->and($thief->do(static fn (Accounts $a, Request $r) => $a->recentlyConfirmed($r)))->toBeFalse();

        // The owner types it: this session, and no other, may for five minutes.
        $owner = $thief;
        $owner->confirm();
        expect($owner->do(static fn (Accounts $a, Request $r) => $a->recentlyConfirmed($r)))->toBeTrue()
            ->and($owner->do(static fn (Accounts $a, Request $r) => $a->confirmedUntil($r)))->toBe($app->now + Accounts::CONFIRMATION_LIFETIME);
        $phone = new Visitor($app);
        $phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        expect(refused(static fn () => $phone->do(static fn (Accounts $a, Request $r) => $a->beginTwoFactor($r, $user))))->toBe($confirmFirst);

        $options = $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user));
        $owner->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($authenticator, $authenticator->register((string) Base64Url::decode($options['challenge']))), 'Laptop'));
        expect($app->kit->accounts->passkeys($user))->toHaveCount(1);

        // Five minutes on, it is asked again.
        $app->now += Accounts::CONFIRMATION_LIFETIME;
        expect($owner->do(static fn (Accounts $a, Request $r) => $a->recentlyConfirmed($r)))->toBeFalse()
            ->and(refused(static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user))))->toBe($confirmFirst);

        // A new session -- a sign-in, which rotates the id -- starts unconfirmed.
        $owner->confirm();
        $owner->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        expect($owner->do(static fn (Accounts $a, Request $r) => $a->recentlyConfirmed($r)))->toBeFalse();
    });
})->with('drivers');

it('confirms a session with one of its account\'s passkeys, or a code from its authenticator app', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $ada = member($app, 'ada@example.com');
        $bob = member($app, 'bob@example.com');
        $user = $ada->state()->user();
        $bobUser = $bob->state()->user();
        \assert($user !== null && $bobUser !== null);
        $adaKey = SoftwareAuthenticator::es256();
        $bobKey = SoftwareAuthenticator::es256();
        foreach ([[$ada, $user, $adaKey], [$bob, $bobUser, $bobKey]] as [$visitor, $owner, $key]) {
            $visitor->confirm();
            $options = $visitor->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $owner));
            $visitor->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $owner, credential($key, $key->register((string) Base64Url::decode($options['challenge']))), 'Key'));
        }
        $app->now += Accounts::CONFIRMATION_LIFETIME;

        $options = $ada->do(static fn (Accounts $a, Request $r) => $a->passkeyConfirmationOptions($r, $user));
        expect($options['rpId'])->toBe('app.example')
            ->and($options['userVerification'])->toBe('required')
            ->and(array_column($options['allowCredentials'], 'id'))->toBe([Base64Url::encode($adaKey->credentialId)]);

        // Another account's passkey confirms nobody here.
        $bobs = $bobKey->assert((string) Base64Url::decode($options['challenge']));
        expect(refused(static fn () => $ada->do(static fn (Accounts $a, Request $r) => $a->confirmWithPasskey($r, $user, credential($bobKey, $bobs)))))->toHaveKey('credential')
            ->and($ada->do(static fn (Accounts $a, Request $r) => $a->recentlyConfirmed($r)))->toBeFalse();

        $options = $ada->do(static fn (Accounts $a, Request $r) => $a->passkeyConfirmationOptions($r, $user));
        $answer = $adaKey->assert((string) Base64Url::decode($options['challenge']));
        $ada->do(static fn (Accounts $a, Request $r) => $a->confirmWithPasskey($r, $user, credential($adaKey, $answer)));
        expect($ada->do(static fn (Accounts $a, Request $r) => $a->recentlyConfirmed($r)))->toBeTrue();
        // Its challenge was spent with it.
        expect(refused(static fn () => $ada->do(static fn (Accounts $a, Request $r) => $a->confirmWithPasskey($r, $user, credential($adaKey, $answer)))))->toHaveKey('credential');

        // With two-factor on, a code from the app confirms; a recovery code does not.
        $secret = (string) Base32::decode($ada->do(static fn (Accounts $a, Request $r) => $a->beginTwoFactor($r, $user))['secret']);
        $codes = $ada->do(static fn (Accounts $a, Request $r) => $a->confirmTwoFactor($r, $user, Totp::at($secret, $app->now)));
        $app->now += Accounts::CONFIRMATION_LIFETIME;
        expect(refused(static fn () => $ada->do(static fn (Accounts $a, Request $r) => $a->confirmWithCode($r, $user, $codes[0]))))->toHaveKey('code')
            ->and($app->kit->accounts->recoveryCodesLeft($user))->toBe(10);
        $ada->do(static fn (Accounts $a, Request $r) => $a->confirmWithCode($r, $user, Totp::at($secret, $app->now)));
        expect($ada->do(static fn (Accounts $a, Request $r) => $a->recentlyConfirmed($r)))->toBeTrue();
    });
});

// Audit (ADR 0153, 0159): an API token outlives the session that made it
// and every sign-in, so a stolen session cookie alone minted a lasting
// credential, and nobody heard of it.
it('asks a session to confirm it is its owner before it issues an API token, and tells the owner', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $store = new ArrayApiTokens();
        $tokens = new ApiTokens($store, $app->kit->tokenHolders, $app->auth, $app->orm, ['' => ['notes:read']], clock: fn (): int => $app->now);
        $issue = static fn () => $owner->do(static fn (Accounts $a, Request $r) => $app->auth->actingAs(
            $user,
            static fn () => $a->issueApiToken($r, $user, $tokens, 'Nightly export', ['notes:read'], CarbonImmutable::createFromTimestampUTC($app->now)->addDays(30)),
        ));

        // A stolen session cookie, its owner's confirmation long lapsed: no token.
        expect(refused($issue))->toBe(['confirm' => [Accounts::CONFIRM_FIRST]])
            ->and($store->tokens)->toBe([]);
        $app->work();
        expect($app->links('api-token-created', 'ada@example.com'))->toBe([]);

        $owner->confirm();
        $issued = $issue();
        expect($issued->token->name)->toBe('Nightly export')
            ->and($store->tokens)->toHaveCount(1);
        $app->work();
        expect($app->links('api-token-created', 'ada@example.com'))->toBe(['https://app.example/account/api-tokens'])
            ->and($app->mail->sent[array_key_last($app->mail->sent)]->text)->not->toContain($issued->plaintext);

        // Five minutes on, it is asked again.
        $app->now += Accounts::CONFIRMATION_LIFETIME;
        expect(refused($issue))->toBe(['confirm' => [Accounts::CONFIRM_FIRST]]);
    });
});

it('tells the owner by email when a passkey is added', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $key = SoftwareAuthenticator::es256();
        $owner->confirm();
        $options = $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user));
        $owner->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($key, $key->register((string) Base64Url::decode($options['challenge']))), 'Laptop'));
        $app->work();

        expect($app->links('passkey-added', 'ada@example.com'))->toBe(['https://app.example/account']);
    });
});

it('sends a reset to an address with an account and nothing to one without, answering both the same', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $asker = new Visitor($app);
        $asker->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $asker->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'nobody@example.com'));
        $app->work();
        expect($app->links('password-reset', 'ada@example.com'))->toHaveCount(1)
            ->and($app->links('password-reset', 'nobody@example.com'))->toBe([]);

        $url = $app->links('password-reset', 'ada@example.com')[0];
        expect($url)->toStartWith('https://app.example/password/reset?token=');
        $asker->do(static fn (Accounts $a, Request $r) => $a->resetPassword($r, token($url), 'a brand new passphrase'));
        expect($asker->who())->toBe('ada@example.com')
            ->and($owner->who())->toBeNull();
        // Used: the link opens nothing again.
        expect($app->kit->accounts->resetOffer(token($url)))->toBeNull();
    });
})->with('drivers');

it('ends every emailed link when the account\'s epoch moves', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $owner = member($app);
        $owner->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $app->work();
        $url = $app->links('password-reset', 'ada@example.com')[0];
        expect($app->kit->accounts->resetOffer(token($url)))->not->toBeNull();

        $owner->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r));
        expect($app->kit->accounts->resetOffer(token($url)))->toBeNull();
    });
});

it('moves an address only by a link to the new one, and keeps one account per address', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $ada = member($app, 'ada@example.com');
        member($app, 'bob@example.com');
        $user = $ada->state()->user();
        \assert($user !== null);

        expect(refused(static fn () => $ada->do(static fn (Accounts $a, Request $r) => $a->requestEmailChange($r, $user, 'ada@example.com', PASSWORD))))->toBe(['email' => [Accounts::SAME_ADDRESS]]);
        $ada->do(static fn (Accounts $a, Request $r) => $a->requestEmailChange($r, $user, 'ADA@new.example', PASSWORD));
        $ada->do(static fn (Accounts $a, Request $r) => $a->requestEmailChange($r, $user, 'bob@example.com', PASSWORD));
        $app->work();
        expect($app->links('email-change', 'bob@example.com'))->toBe([]);

        $url = $app->links('email-change', 'ada@new.example')[0];
        expect($app->kit->accounts->emailChangeOffer(token($url))['email'] ?? null)->toBe('ada@new.example');
        $ada->do(static fn (Accounts $a, Request $r) => $a->confirmEmailChange($r, token($url)));
        $app->work();
        expect($ada->who())->toBe('ada@new.example')
            ->and($app->links('email-changed', 'ada@example.com'))->toHaveCount(1);
    });
})->with('drivers');

it('ends every other session when the address changes, as a change of password does, and keeps the browser that changed it signed in', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        // A session that got in elsewhere -- the one a thief holds, say.
        $other = new Visitor($app);
        $other->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        expect($other->who())->toBe('ada@example.com');

        $owner->do(static fn (Accounts $a, Request $r) => $a->requestEmailChange($r, $user, 'ada@new.example', PASSWORD));
        $app->work();
        $owner->do(static fn (Accounts $a, Request $r) => $a->confirmEmailChange($r, token($app->links('email-change', 'ada@new.example')[0])));

        expect($other->who())->toBeNull()
            ->and($owner->who())->toBe('ada@new.example');
    });
})->with('drivers');

it('builds links on the configured https origin, stands in on localhost only outside production, and fails closed in it', function () {
    expect(AccountLinks::origin('https://app.example/', env: []))->toBe('https://app.example')
        ->and(AccountLinks::origin(null, 'http://127.0.0.1:6123', []))->toBe('http://localhost:6123')
        ->and(AccountLinks::origin(null, null, []))->toBe('http://localhost')
        ->and(static fn () => AccountLinks::origin(null, 'http://127.0.0.1:6123', ['TINVAR_ENV' => 'production']))->toThrow(\InvalidArgumentException::class, 'APP_URL is not set')
        ->and(static fn () => AccountLinks::origin('http://localhost:8080', null, ['TINVAR_ENV' => 'production']))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => AccountLinks::origin('https://app.example/path', null, []))->toThrow(\InvalidArgumentException::class);
});

it('refuses to build links on plain http where the environment says production', function () {
    $previous = getenv('TINVAR_ENV');
    putenv('TINVAR_ENV=production');
    try {
        expect(static fn (): MemberApp => new MemberApp(PdoConnection::sqlite(':memory:', 'accounts'), 'http://localhost:8080'))
            ->toThrow(\InvalidArgumentException::class, 'https alone');
    } finally {
        putenv($previous === false ? 'TINVAR_ENV' : "TINVAR_ENV={$previous}");
    }
});

it('moves the credential epoch API tokens are held to on sign out everywhere, a password reset or change and an address change, and not at a sign-in', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $id = $user->authIdentifier();
        $credential = static fn (): string => $app->kit->tokenHolders->epoch($user);
        expect($app->kit->tokenHolders->find($id)?->authIdentifier())->toBe($id)
            ->and($credential())->toBe('0');

        // Signing in and out again, on another device and this one, moves the auth epoch alone.
        $phone = new Visitor($app);
        $phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $phone->do(static fn (Accounts $a, Request $r) => $a->signOut($r));
        $owner->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        expect($credential())->toBe('0');

        $owner->do(static fn (Accounts $a, Request $r) => $a->changePassword($r, $user, PASSWORD, 'a second passphrase here'));
        expect($credential())->toBe('1');

        $owner->do(static fn (Accounts $a, Request $r) => $a->requestEmailChange($r, $user, 'ada@new.example', 'a second passphrase here'));
        $app->work();
        $owner->do(static fn (Accounts $a, Request $r) => $a->confirmEmailChange($r, token($app->links('email-change', 'ada@new.example')[0])));
        expect($credential())->toBe('2');

        $owner->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@new.example'));
        $app->work();
        $owner->do(static fn (Accounts $a, Request $r) => $a->resetPassword($r, token($app->links('password-reset', 'ada@new.example')[0]), PASSWORD));
        expect($credential())->toBe('3');

        $owner->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r));
        expect($credential())->toBe('4');
    });
})->with('drivers');

it('runs a token\'s request on its user\'s auth epoch, as jobs, links and cached answers read it, while the token is held to the credential epoch', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        // Signed in on another device: the auth epoch moves, the credential epoch does not.
        $phone = new Visitor($app);
        $phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $auth = $app->kit->lookup->epoch($user);
        expect($app->kit->tokenHolders->epoch($user))->toBe('0')
            ->and($auth)->not->toBe('0');

        $tokens = new ApiTokens(new ArrayApiTokens(), $app->kit->tokenHolders, $app->auth, $app->orm, ['' => ['notes:read']], clock: fn (): int => $app->now);
        $owner->confirm();
        $issued = $owner->do(static fn (Accounts $a, Request $r) => $app->auth->actingAs(
            $user,
            static fn () => $a->issueApiToken($r, $user, $tokens, 'Nightly export', ['notes:read'], CarbonImmutable::createFromTimestampUTC($app->now)->addDays(30)),
        ));

        // A job it dispatches, a link it mints and a query it caches are
        // each bound to the epoch it runs on, and checked against the auth
        // epoch: run on the credential epoch, a job was refused, and an
        // answer cached under it outlived the role change that moves the
        // auth epoch alone.
        $presented = $tokens->authenticate($issued->plaintext);
        expect($presented?->epoch)->toBe($auth)
            ->and($issued->token->epoch)->toBe('0');

        // Still held to the credential epoch: a sign-in elsewhere keeps it,
        // signing out everywhere ends it.
        $phone->do(static fn (Accounts $a, Request $r) => $a->signOut($r));
        expect($tokens->authenticate($issued->plaintext)?->epoch)->toBe($app->kit->lookup->epoch($user));
        $owner->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r));
        expect($tokens->authenticate($issued->plaintext))->toBeNull();
    });
});

it('ends every other session and token when two-factor is turned off, and tells the owner', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $id = $user->authIdentifier();
        $owner->confirm();
        $secret = (string) Base32::decode($owner->do(static fn (Accounts $a, Request $r) => $a->beginTwoFactor($r, $user))['secret']);
        $owner->do(static fn (Accounts $a, Request $r) => $a->confirmTwoFactor($r, $user, Totp::at($secret, $app->now)));

        // A session that got in elsewhere -- the one a thief holds, say.
        $other = new Visitor($app);
        $other->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $app->now += Totp::PERIOD;
        $other->do(static fn (Accounts $a, Request $r) => $a->completeSecondFactor($r, Totp::at($secret, $app->now)));
        expect($other->who())->toBe('ada@example.com');
        $epoch = $app->kit->epochs->of($id);
        $credential = $app->kit->credentials->of($id);

        $owner->do(static fn (Accounts $a, Request $r) => $a->disableTwoFactor($r, $user, PASSWORD));
        $app->work();

        expect($app->kit->accounts->twoFactorEnabled($user))->toBeFalse()
            ->and($other->who())->toBeNull()
            ->and($owner->who())->toBe('ada@example.com')
            ->and($app->kit->epochs->of($id))->not->toBe($epoch)
            ->and($app->kit->credentials->of($id))->not->toBe($credential)
            ->and($app->links('two-factor-disabled', 'ada@example.com'))->toBe(['https://app.example/password/forgot']);
    });
})->with('drivers');

it('signs a handoff\'s arrival in on the host it came to, leaving the epoch, and ends that session when its code comes back', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $home = member($app);
        member($app, 'bob@example.com');
        $ada = $home->state()->user();
        \assert($ada !== null);
        $epoch = $app->kit->epochs->of($ada->authIdentifier());

        // Someone else was signed in on the admin host in this browser.
        $admin = new Visitor($app, 'admin.app.example');
        $admin->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'bob@example.com', PASSWORD));
        $bob = $admin->state()->user();
        \assert($bob !== null);
        $bobEpoch = $app->kit->epochs->of($bob->authIdentifier());
        $before = $admin->cookie;

        $arrival = new Arrival($ada, $epoch, Realm::platform('https://admin.app.example'), '/orders', '', 'redemption-1');
        $id = $admin->do(static fn (Accounts $a, Request $r): string => $app->kit->arrivals->arrive($r, $arrival));
        expect($admin->cookie)->not->toBe($before)
            ->and($admin->state()->id)->toBe($id)
            ->and($admin->who())->toBe('ada@example.com')
            // The same person as on the shop's host: nothing a tab of theirs holds there stops counting.
            ->and($app->kit->epochs->of($ada->authIdentifier()))->toBe($epoch)
            ->and($home->who())->toBe('ada@example.com')
            // Whoever the session belonged to before moves on.
            ->and($app->kit->epochs->of($bob->authIdentifier()))->not->toBe($bobEpoch);

        // A session is made for the host the request came to, and no other.
        $elsewhere = new Visitor($app, 'admin.app.example');
        $wrong = new Arrival($ada, $epoch, Realm::platform('https://app.example'), '/', '', 'redemption-2');
        expect(static fn () => $elsewhere->do(static fn (Accounts $a, Request $r) => $app->kit->arrivals->arrive($r, $wrong)))->toThrow(\LogicException::class)
            ->and($elsewhere->who())->toBeNull();

        // Its code shown again ends the session it started, and no other.
        $app->kit->arrivals->revoke('');
        expect($admin->who())->toBe('ada@example.com');
        $app->kit->arrivals->revoke('redemption-1');
        expect($admin->who())->toBeNull()
            ->and($home->who())->toBe('ada@example.com');
    });
})->with('drivers');

it('adds the handoff column to a sessions table made before it, and signs in on one that lacks it', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $connection = $app->orm->connection();
        $schema = new Schema($connection, Dialect::for($connection));
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->dropIndex('sessions_handoff');
        });
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->dropColumn('handoff_redemption');
        });

        $visitor = member($app);
        expect($visitor->who())->toBe('ada@example.com')
            ->and($schema->hasColumn('sessions', 'handoff_redemption'))->toBeFalse();

        Sessions::addHandoffRedemption($schema);
        Sessions::addHandoffRedemption($schema);
        $user = $visitor->state()->user();
        \assert($user !== null);
        $admin = new Visitor($app, 'admin.app.example');
        $arrival = new Arrival($user, $app->kit->epochs->of($user->authIdentifier()), Realm::platform('https://admin.app.example'), '/', '', 'redemption');
        $admin->do(static fn (Accounts $a, Request $r): string => $app->kit->arrivals->arrive($r, $arrival));
        expect($admin->who())->toBe('ada@example.com');
        $app->kit->arrivals->revoke('redemption');
        expect($admin->who())->toBeNull();
    });
});

it('starts a handoff for a visitor on another of the app\'s hosts, and sends one on its own host to sign in', function () {
    $realms = new Realms(Realm::platform('https://app.example'), [Realm::platform('https://admin.app.example', panel: 'admin')], platformPanels: ['admin']);
    $auth = new AuthContext();
    $through = static fn (Request $r): Response => Response::make(200, 'in');
    $require = new RequireSignIn($auth, new AccountPaths(), $realms);

    $admin = $require->handle(new Request('GET', '/orders', ['host' => 'admin.app.example'], ['page' => '2'], rawQuery: 'page=2'), $through);
    $home = $require->handle(new Request('GET', '/account', ['host' => 'app.example']), $through);
    expect($admin->header('location'))->toBe(HandoffEndpoint::START . '?return=' . rawurlencode('/orders?page=2'))
        ->and($home->header('location'))->toBe('/sign-in?next=' . rawurlencode('/account'))
        // An app with one host has no realms: its sign-in page, wherever.
        ->and(new RequireSignIn($auth, new AccountPaths())->handle(new Request('GET', '/orders', ['host' => 'admin.app.example']), $through)->header('location'))
        ->toBe('/sign-in?next=' . rawurlencode('/orders'));

    // Where it goes back to is held as a sign-in's next is: a page here, and nothing else.
    $start = $require->handle(new Request('GET', HandoffEndpoint::START, ['host' => 'admin.app.example']), $through);
    expect($start->header('location'))->toBe(HandoffEndpoint::START . '?return=' . rawurlencode('/account'));

    $auth->login(new class implements \Tinvar\Auth\Authenticatable {
        public function authIdentifier(): string
        {
            return 'u1';
        }
    }, '1');
    expect($require->handle(new Request('GET', '/orders', ['host' => 'admin.app.example']), $through)->body())->toBe('in');
});

it('goes on after signing in to the handoff\'s authorize step, and to nothing else under /_', function () {
    $paths = new AccountPaths(home: '/orders');
    expect($paths->next(HandoffEndpoint::AUTHORIZE . '?code=abc&state=def'))->toBe(HandoffEndpoint::AUTHORIZE . '?code=abc&state=def')
        ->and($paths->next(HandoffEndpoint::START . '?return=%2F'))->toBe('/orders')
        ->and($paths->next('/_action/account.signOut'))->toBe('/orders')
        ->and($paths->next('/_handoff/authorizeX'))->toBe('/orders')
        ->and($paths->next('//evil.example' . HandoffEndpoint::AUTHORIZE))->toBe('/orders')
        ->and($paths->next('/orders?page=2'))->toBe('/orders?page=2');
});

it('signs in by an emailed link, once, from the POST and not the look, sent only to an address with an account', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        member($app);
        $visitor = new Visitor($app);
        $visitor->do(static fn (Accounts $a, Request $r) => $a->requestSignInLink($r, ' Ada@Example.com '));
        $visitor->do(static fn (Accounts $a, Request $r) => $a->requestSignInLink($r, 'nobody@example.com'));
        $app->work();
        $links = $app->links('sign-in-link', 'ada@example.com');
        expect($links)->toHaveCount(1)
            ->and($links[0])->toStartWith('https://app.example/sign-in/link?token=')
            ->and($app->links('sign-in-link', 'nobody@example.com'))->toBe([]);
        $token = token($links[0]);

        // Looking spends nothing.
        expect($app->kit->accounts->signInLinkOffer($token)?->accountEmail())->toBe('ada@example.com')
            ->and($app->kit->accounts->signInLinkOffer($token)?->accountEmail())->toBe('ada@example.com')
            ->and($visitor->who())->toBeNull();

        $before = $visitor->cookie;
        expect($visitor->do(static fn (Accounts $a, Request $r) => $a->signInWithLink($r, $token)))->toBe(SignInResult::SignedIn)
            ->and($visitor->who())->toBe('ada@example.com')
            ->and($visitor->cookie)->not->toBe($before);

        // Good once, and no reset link either way round.
        $again = new Visitor($app);
        expect(refused(static fn () => $again->do(static fn (Accounts $a, Request $r) => $a->signInWithLink($r, $token))))->toBe(['token' => [Accounts::LINK_REFUSED]])
            ->and($app->kit->accounts->signInLinkOffer($token))->toBeNull()
            ->and($again->who())->toBeNull();
        $again->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $app->work();
        $reset = token($app->links('password-reset', 'ada@example.com')[0]);
        expect(refused(static fn () => $again->do(static fn (Accounts $a, Request $r) => $a->signInWithLink($r, $reset))))->toBe(['token' => [Accounts::LINK_REFUSED]])
            ->and($app->kit->accounts->resetOffer($reset))->not->toBeNull();
    });
})->with('drivers');

it('ends a sign-in link when the account signs in anywhere, and asks it for the second factor where two-factor is on', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $owner = member($app);
        $owner->do(static fn (Accounts $a, Request $r) => $a->requestSignInLink($r, 'ada@example.com'));
        $app->work();
        $first = token($app->links('sign-in-link', 'ada@example.com')[0]);
        new Visitor($app)->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        expect($app->kit->accounts->signInLinkOffer($first))->toBeNull();

        // Signed in elsewhere moves the epoch, and signs nobody out.
        $user = $owner->state()->user();
        \assert($user !== null);
        $owner->confirm();
        $secret = (string) Base32::decode($owner->do(static fn (Accounts $a, Request $r) => $a->beginTwoFactor($r, $user))['secret']);
        $owner->do(static fn (Accounts $a, Request $r) => $a->confirmTwoFactor($r, $user, Totp::at($secret, $app->now)));

        $phone = new Visitor($app);
        $phone->do(static fn (Accounts $a, Request $r) => $a->requestSignInLink($r, 'ada@example.com'));
        $app->work();
        $links = $app->links('sign-in-link', 'ada@example.com');
        expect($phone->do(static fn (Accounts $a, Request $r) => $a->signInWithLink($r, token($links[array_key_last($links)]))))->toBe(SignInResult::SecondFactor)
            ->and($phone->who())->toBeNull()
            ->and($phone->state()->pending()?->accountEmail())->toBe('ada@example.com');
    });
});

it('refuses to send sign-in links for an app whose mails cannot say one', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $plain = new MemberApp($app->orm->connection(), mails: new \Tinvar\Tests\Accounts\Fixtures\PlainMemberMails());
        expect(static fn () => new Visitor($plain)->do(static fn (Accounts $a, Request $r) => $a->requestSignInLink($r, 'ada@example.com')))
            ->toThrow(\LogicException::class, 'SignInLinkMails');
    });
});

it('fails an account email\'s job at once when the provider refuses the address for good, rather than make a link a try', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        member($app);
        $refusing = new MemberApp($app->orm->connection(), transport: new class implements \Tinvar\Mail\Transport {
            public function send(\Tinvar\Mail\Message $message): void
            {
                throw new \Tinvar\Mail\MailTransportException('The provider refused the address for good.', retryable: false);
            }
        });
        new Visitor($refusing)->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));

        expect(static fn () => $refusing->work())->toThrow(\Tinvar\Jobs\JobRefused::class);
    });
});

it('keeps a stored hash the app says to keep though the policy has moved on, and replaces any other at sign-in', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        member($app);
        $connection = $app->orm->connection();
        $cheap = password_hash(PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $connection->execute('UPDATE members SET password_hash = ?', [$cheap]);
        $stored = static fn (): mixed => $connection->select('SELECT password_hash FROM members')[0]['password_hash'];

        $seeded = static fn (string $hash): bool => (password_get_info($hash)['options']['cost'] ?? null) === 4;
        $keeping = new MemberApp($connection, keepHash: $seeded);
        new Visitor($keeping)->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        expect($stored())->toBe($cheap);

        new Visitor(new MemberApp($connection))->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        expect($stored())->not->toBe($cheap)
            ->and(password_verify(PASSWORD, (string) $stored()))->toBeTrue();
    });
});

// Made only for an unknown email, the decoy cost a sample of the accounts
// read and a hash made where a known one cost neither: wherever the kit is
// made per request, every refusal of an unknown email took that much
// longer, and said there was no account.
it('makes the sign-in decoy on the first check a worker serves, whatever its email, and once', function () {
    $sampled = 0;
    $passwords = new Passwords(static function (int $limit) use (&$sampled): array {
        $sampled++;

        return [password_hash('another password', PASSWORD_BCRYPT, ['cost' => 4])];
    });
    $hash = password_hash(PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);

    expect($passwords->verify(PASSWORD, $hash))->toBeTrue()
        ->and($sampled)->toBe(1)
        ->and($passwords->verify('a wrong password', null))->toBeFalse()
        ->and($passwords->verify('a wrong password', $hash))->toBeFalse()
        ->and($sampled)->toBe(1);
});

// A hash the app keeps is kept off the policy on purpose -- its seeded demo
// accounts, hashed cheaply -- and says nothing of what an account costs to
// check. Counted, a database of them set the decoy at their cost, and an
// account made at the policy's took two hundred times longer to refuse a
// wrong password for than an unknown email took: which said it was there.
it('hashes the sign-in decoy as the accounts the policy governs are, leaving out the hashes the app keeps', function () {
    $seeded = static fn (string $hash): bool => (password_get_info($hash)['options']['cost'] ?? null) === 4;
    $cheap = static fn (): string => password_hash('a seeded password', PASSWORD_BCRYPT, ['cost' => 4]);
    $made = password_hash('a password chosen since', PASSWORD_BCRYPT, ['cost' => 5]);
    $cost = static fn (string $hash): mixed => password_get_info($hash)['options']['cost'] ?? null;

    // Mostly seeded, one made since: the decoy costs what the made one does.
    $mixed = new Passwords(static fn (int $limit): array => [$cheap(), $cheap(), $cheap(), $made], $seeded);
    // Seeded alone: the policy's own.
    $seededOnly = new Passwords(static fn (int $limit): array => [$cheap(), $cheap()], $seeded);
    // None kept: the most common, seeded or not.
    $keepingNone = new Passwords(static fn (int $limit): array => [$cheap(), $cheap(), $made]);

    expect($cost($mixed->decoy()))->toBe(5)
        ->and($cost($seededOnly->decoy()))->toBe($cost($seededOnly->hash('a password chosen now')))
        ->and($cost($keepingNone->decoy()))->toBe(4);
});

it('hands the app the passwords it hashes with, for an account it makes itself', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $container = $app->kit->register(new \Tinvar\Container\Container());
        $hash = $app->kit->passwords->hash(PASSWORD);
        expect($container->get(Passwords::class))->toBe($app->kit->passwords)
            ->and($app->kit->passwords->verify(PASSWORD, $hash))->toBeTrue()
            ->and($app->kit->passwords->needsRehash($hash))->toBeFalse();
    });
});

it('makes and opens no link, sends no account email and starts no passkey ceremony for an app with no https origin, and signs in by password', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $owner = member($app);
        $owner->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $app->work();
        $made = token($app->links('password-reset', 'ada@example.com')[0]);

        $none = new MemberApp($app->orm->connection(), origin: null);
        $visitor = new Visitor($none);
        $visitor->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $visitor->do(static fn (Accounts $a, Request $r) => $a->requestSignUp($r, 'new@example.com', 'New'));
        $visitor->do(static fn (Accounts $a, Request $r) => $a->requestSignInLink($r, 'ada@example.com'));
        $none->work();
        expect($none->mail->sent)->toBe([])
            ->and($none->kit->accountLinks->origin)->toBeNull()
            // A link made where there was an origin opens nothing here.
            ->and($none->kit->accounts->resetOffer($made))->toBeNull()
            ->and(refused(static fn () => $visitor->do(static fn (Accounts $a, Request $r) => $a->resetPassword($r, $made, 'a brand new passphrase'))))->toBe(['token' => [Accounts::LINK_REFUSED]])
            ->and(refused(static fn () => $visitor->do(static fn (Accounts $a, Request $r) => $a->passkeySignInOptions($r))))->toBe(['credential' => [Accounts::WRONG_HOST]])
            ->and($visitor->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD)))->toBe(SignInResult::SignedIn);

        // A change the owner is told of by email is still made, and nothing is sent.
        $user = $visitor->state()->user();
        \assert($user !== null);
        $visitor->do(static fn (Accounts $a, Request $r) => $a->changePassword($r, $user, PASSWORD, 'a brand new passphrase'));
        $none->work();
        expect($none->mail->sent)->toBe([])
            ->and($visitor->who())->toBe('ada@example.com');
    });
});

it('records sign-ins, failed and not, sign-outs and each change to how an account signs in, in the audit log it is given', function () {
    onDriver('sqlite', function (MemberApp $plain): void {
        $connection = $plain->orm->connection();
        $app = new MemberApp($connection, audit: true);
        $rows = static fn (): array => array_map(
            static fn (array $row): array => [$row['event'], $row['actor_id'], $row['subject_id'], $row['data']],
            $connection->select('SELECT event, actor_id, subject_id, data FROM audit_entries ORDER BY sequence'),
        );

        $ada = member($app);
        $user = $ada->state()->user();
        \assert($user !== null);
        $id = $user->authIdentifier();
        $stranger = new Visitor($app);
        refused(static fn () => $stranger->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', 'not the password at all')));
        refused(static fn () => $stranger->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'nobody@example.com', 'not the password at all')));
        $stranger->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $stranger->do(static fn (Accounts $a, Request $r) => $a->signOut($r));
        $ada->do(static fn (Accounts $a, Request $r) => $a->changePassword($r, $user, PASSWORD, 'another long passphrase'));
        $ada->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $app->work();
        $reset = new Visitor($app);
        $reset->do(static fn (Accounts $a, Request $r) => $a->resetPassword($r, token($app->links('password-reset', 'ada@example.com')[0]), PASSWORD));
        $reset->do(static fn (Accounts $a, Request $r) => $a->requestEmailChange($r, $user, 'ada@new.example', PASSWORD));
        $app->work();
        $reset->do(static fn (Accounts $a, Request $r) => $a->confirmEmailChange($r, token($app->links('email-change', 'ada@new.example')[0])));
        $reset->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r));

        expect($rows())->toBe([
            // The sign-up's sign-in, as the account it made.
            ['auth.signed_in', $id, $id, null],
            // In the account's log where there is one, by the address tried, as nobody.
            ['auth.sign_in_failed', null, $id, '{"email":"ada@example.com"}'],
            ['auth.sign_in_failed', null, null, '{"email":"nobody@example.com"}'],
            ['auth.signed_in', $id, $id, null],
            ['auth.signed_out', $id, $id, null],
            ['auth.password_changed', $id, $id, null],
            // On the worker, once its email went.
            ['auth.password_reset_requested', null, $id, null],
            // By the link, so as a visitor; then the sign-in after it.
            ['auth.password_changed', null, $id, null],
            ['auth.signed_in', $id, $id, null],
            // By the link, which the browser it reached may open signed in or not.
            ['auth.email_changed', $id, $id, null],
            ['auth.signed_out_everywhere', $id, $id, null],
        ]);
    });
});
