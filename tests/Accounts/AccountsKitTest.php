<?php

declare(strict_types=1);

/**
 * The accounts kit's flows (ADR 0153) over an application's own users table
 * -- `members`, through the AccountUsers contract -- on SQLite and, where
 * they are there, Postgres and MySQL: sessions with rows and their realms, the second
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
use Tinvar\Api\ApiTokenChoice;
use Tinvar\Api\ApiTokenRefused;
use Tinvar\Api\ApiTokens;
use Tinvar\Api\ArrayApiTokens;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\UserLookup;
use Tinvar\Http\DatabaseNonceStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\Arrival;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\HandoffCode;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Support\Signer;
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
    $missing = $driver === 'pgsql' ? Databases::postgresMissing() : Databases::mysqlMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    \Tinvar\Tests\Orm\Fixtures\Databases::inScratchSchema($driver, static function (\PDO $pdo) use ($driver, $run): void {
        if ($driver !== 'mysql') {
            $run(new PdoConnection($pdo, 'accounts'));

            return;
        }
        // The ORM quotes names as standard SQL does, which MySQL reads so
        // with ANSI_QUOTES: on for the test, and put back for the next.
        $mode = $pdo->query('SELECT @@SESSION.sql_mode')?->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
        try {
            $run(new PdoConnection($pdo, 'accounts'));
        } finally {
            $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote(\is_string($mode) ? $mode : ''));
        }
    });
}

/**
 * Runs $then once, as a request on another worker would, at the first
 * statement answered outside a transaction from the one naming $after on:
 * a revocation landing between a sign-in's check and the transaction that
 * makes its session. The closure returned says whether it ran, so a test
 * holds the interleaving it meant to make.
 *
 * @return \Closure(): bool
 */
function between(MemberApp $app, string $after, \Closure $then): \Closure
{
    $connection = $app->orm->connection();
    \assert($connection instanceof PdoConnection);
    $seen = false;
    $ran = false;
    $connection->observeStatements(static function (string $sql) use ($connection, $after, $then, &$seen, &$ran): void {
        $seen = $seen || str_contains($sql, $after);
        if (!$seen || $connection->inTransaction()) {
            return;
        }
        $connection->observeStatements(null);
        $ran = true;
        $then();
    });

    return static function () use (&$ran): bool {
        return $ran;
    };
}

/**
 * Whom $visitor's cookie signs in at its next request, on a worker that
 * has read nothing yet: a request starts with no account loaded, which the
 * Visitor's own app, kept from request to request, does not.
 */
function nextRequest(Visitor $visitor): ?string
{
    $worker = new MemberApp($visitor->app->orm->connection());
    $worker->now = $visitor->app->now;
    $fresh = new Visitor($worker, $visitor->host, $visitor->address);
    $fresh->cookie = $visitor->cookie;

    return $fresh->who();
}

/** The kit's users as the handoff endpoint reads them, with a moment once it has read an epoch. */
final class EpochReadFirst implements UserLookup
{
    public ?\Closure $then = null;

    public function __construct(private readonly UserLookup $users) {}

    public function find(string $id): ?Authenticatable
    {
        return $this->users->find($id);
    }

    public function epoch(Authenticatable $user): string
    {
        $epoch = $this->users->epoch($user);
        $then = $this->then;
        $this->then = null;
        if ($then !== null) {
            $then();
        }

        return $epoch;
    }
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

dataset('drivers', ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql'], 'mysql' => ['mysql']]);

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

// A sign-in checks its credential -- a password, a link, a code, a passkey,
// a handoff's epoch -- before the transaction that makes its session, and a
// revocation deletes the rows it sees. One that commits in between used to
// leave the sign-in a session, for the cookie's twelve hours, that its
// owner had just ended everywhere. Each revocation lands here as a request
// on another worker would, between the check and the session.

it('ends a sign-in by password that checked the password a change elsewhere had just replaced', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        // The owner changes the password on another worker, which has its
        // own account loaded: the thief's worker still holds the old hash.
        $worker = new MemberApp($app->orm->connection());
        $worker->now = $app->now;
        $ownerThere = new Visitor($worker);
        $ownerThere->cookie = $owner->cookie;

        $thief = new Visitor($app);
        $ran = between($app, 'FROM "members" WHERE "email"', static function () use ($ownerThere): void {
            $ownerThere->do(static fn (Accounts $a, Request $r) => $a->changePassword($r, SessionState::of($r)->user() ?? throw new \LogicException('The owner is signed out.'), PASSWORD, 'a new passphrase of the owner'));
        });
        $thief->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));

        expect($ran())->toBeTrue()
            ->and(nextRequest($thief))->toBeNull()
            ->and(nextRequest($ownerThere))->toBe('ada@example.com');
    });
})->with('drivers');

it('ends a sign-in by an emailed link that a sign-out everywhere overtook once the link was used', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $owner->do(static fn (Accounts $a, Request $r) => $a->requestSignInLink($r, 'ada@example.com'));
        $app->work();
        $token = token($app->links('sign-in-link', 'ada@example.com')[0]);

        $phone = new Visitor($app);
        $ran = between($app, 'SET used_at', static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r)));
        $phone->do(static fn (Accounts $a, Request $r) => $a->signInWithLink($r, $token));

        expect($ran())->toBeTrue()
            ->and(nextRequest($phone))->toBeNull()
            ->and(nextRequest($owner))->toBeNull();
    });
})->with('drivers');

it('refuses a second factor whose sign-in a sign-out everywhere ended once the code was checked', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $owner->confirm();
        $secret = (string) Base32::decode($owner->do(static fn (Accounts $a, Request $r) => $a->beginTwoFactor($r, $user))['secret']);
        $owner->do(static fn (Accounts $a, Request $r) => $a->confirmTwoFactor($r, $user, Totp::at($secret, $app->now)));

        $phone = new Visitor($app);
        $phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $app->now += Totp::PERIOD;
        $ran = between($app, 'SET last_used_step', static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r)));

        expect(refused(static fn () => $phone->do(static fn (Accounts $a, Request $r) => $a->completeSecondFactor($r, Totp::at($secret, $app->now)))))->toBe(['code' => [Accounts::SECOND_FACTOR_EXPIRED]])
            ->and($ran())->toBeTrue()
            ->and(nextRequest($phone))->toBeNull();
    });
})->with('drivers');

it('ends a sign-in by passkey when that passkey is removed once it was checked', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $key = SoftwareAuthenticator::es256();
        $owner->confirm();
        $options = $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user));
        $passkey = $owner->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($key, $key->register((string) Base64Url::decode($options['challenge']))), 'Laptop'));

        // The thief holds the key; the owner, missing it, removes it.
        $thief = new Visitor($app);
        $challenge = (string) Base64Url::decode($thief->do(static fn (Accounts $a, Request $r) => $a->passkeySignInOptions($r))['challenge']);
        $answer = credential($key, $key->assert($challenge), $passkey->userHandle);
        $ran = between($app, 'SET sign_count', static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->removePasskey($r, $user, $passkey->id)));
        $thief->do(static fn (Accounts $a, Request $r) => $a->signInWithPasskey($r, $answer));

        expect($ran())->toBeTrue()
            ->and($app->kit->accounts->passkeys($user))->toBe([])
            ->and(nextRequest($thief))->toBeNull()
            ->and(nextRequest($owner))->toBe('ada@example.com');
    });
})->with('drivers');

it('refuses a handoff whose user signed out everywhere after the endpoint read their epoch, and makes no session for it', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $connection = $app->orm->connection();
        DatabaseNonceStore::define(new Schema($connection, Dialect::for($connection)), 'handoff_nonces');
        $kit = $app->kit;
        $lookup = new EpochReadFirst($kit->lookup);
        $endpoint = new HandoffEndpoint(
            realms: new Realms(Realm::platform('https://app.example'), [Realm::platform('https://admin.app.example')]),
            signer: new Signer(MemberApp::KEY, HandoffCode::PURPOSE),
            nonces: new DatabaseNonceStore($connection, 'handoff_nonces', durable: true),
            sessionId: static fn (Request $r): string => $kit->cookie->of($r),
            auth: $app->auth,
            orm: $app->orm,
            users: $lookup,
            sessions: $kit->arrivals,
            signIn: static fn (string $next): Response => Response::redirect('/sign-in'),
            clock: static fn (): int => $app->now,
        );
        // One step of the handoff, as the browser $visitor asks it, with
        // the session opened as the middleware opens it; the query the
        // step's redirect carries to the next.
        $step = static function (Visitor $visitor, string $path, array $query) use ($app, $endpoint): array {
            $request = new Request('GET', $path, ['host' => $visitor->host], query: $query, cookies: [$app->kit->cookie->name => $visitor->cookie], rawQuery: http_build_query($query, '', '&', PHP_QUERY_RFC3986), remoteAddress: $visitor->address);
            $state = $app->kit->sessions->open($request);
            $app->auth->login($state->user(), $state->epoch());
            try {
                $location = (string) $endpoint->handle($request->withAttribute(SessionState::ATTRIBUTE, $state))->header('location');
            } finally {
                $visitor->cookie = $state->replacement() ?? $visitor->cookie;
                $app->auth->login(null);
            }
            parse_str((string) parse_url($location, PHP_URL_QUERY), $next);

            return $next;
        };

        $home = member($app);
        $admin = new Visitor($app, 'admin.app.example');
        $code = $step($home, HandoffEndpoint::AUTHORIZE, $step($admin, HandoffEndpoint::START, ['return' => '/orders']));

        $lookup->then = static fn () => $home->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r));
        expect(static fn () => $step($admin, HandoffEndpoint::CALLBACK, $code))->toThrow(HttpException::class, "its user's auth epoch moved while it was being redeemed")
            ->and($lookup->then)->toBeNull()
            ->and(nextRequest($admin))->toBeNull()
            ->and(nextRequest($home))->toBeNull();
    });
})->with('drivers');

it('ends the sign-in a password reset makes when a sign-out everywhere lands once the reset is made', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        member($app);
        $asker = new Visitor($app);
        $asker->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $app->work();
        $token = token($app->links('password-reset', 'ada@example.com')[0]);

        // The owner, at another browser, signs in with the new password and
        // ends every session.
        $owner = new Visitor($app);
        $ran = between($app, 'SET used_at', static function () use ($owner): void {
            $owner->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', 'a brand new passphrase'));
            $owner->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r));
        });
        $asker->do(static fn (Accounts $a, Request $r) => $a->resetPassword($r, $token, 'a brand new passphrase'));

        expect($ran())->toBeTrue()
            ->and(nextRequest($asker))->toBeNull()
            ->and(nextRequest($owner))->toBeNull();
    });
})->with('drivers');

// A change a signed-in session makes -- a new password, two-factor off, a
// passkey removed -- is checked against the session the request opened, and
// signs that browser in again. A revocation committing once the request has
// begun ended the session, and the change would otherwise move the epoch on
// past it: the thief's new password undoing the owner's reset.

it('refuses a change of password that a reset elsewhere overtook once the request began, and keeps the reset', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $thief = member($app);
        $user = $thief->state()->user();
        \assert($user !== null);
        // The owner resets the password at another worker, its own account loaded.
        $worker = new MemberApp($app->orm->connection());
        $worker->now = $app->now;
        $owner = new Visitor($worker);
        $owner->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $worker->work();
        $token = token($worker->links('password-reset', 'ada@example.com')[0]);

        $ran = between($app, 'SET confirmed_at', static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->resetPassword($r, $token, 'the owner chose this one')));
        expect(refused(static fn () => $thief->do(static fn (Accounts $a, Request $r) => $a->changePassword($r, $user, PASSWORD, 'the thief chose this one'))))->toBe(['currentPassword' => [Accounts::SESSION_ENDED]])
            ->and($ran())->toBeTrue()
            ->and(nextRequest($thief))->toBeNull()
            ->and(nextRequest($owner))->toBe('ada@example.com');

        // The reset stands: the owner's password signs in, and the thief's was never set.
        $later = new Visitor(new MemberApp($app->orm->connection()));
        $later->app->now = $app->now;
        expect(refused(static fn () => $later->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', 'the thief chose this one'))))->toBe(['password' => [Accounts::NO_MATCH]])
            ->and($later->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', 'the owner chose this one')))->toBe(SignInResult::SignedIn);
    });
})->with('drivers');

it('refuses to turn two-factor off for a session a sign-out everywhere ended once the request began', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $owner->confirm();
        $secret = (string) Base32::decode($owner->do(static fn (Accounts $a, Request $r) => $a->beginTwoFactor($r, $user))['secret']);
        $owner->do(static fn (Accounts $a, Request $r) => $a->confirmTwoFactor($r, $user, Totp::at($secret, $app->now)));

        // A session that got past the code: the one a thief holds, say.
        $thief = new Visitor($app);
        $thief->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $app->now += Totp::PERIOD;
        $thief->do(static fn (Accounts $a, Request $r) => $a->completeSecondFactor($r, Totp::at($secret, $app->now)));

        $ran = between($app, 'SET confirmed_at', static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r)));
        expect(refused(static fn () => $thief->do(static fn (Accounts $a, Request $r) => $a->disableTwoFactor($r, $user, PASSWORD))))->toBe(['currentPassword' => [Accounts::SESSION_ENDED]])
            ->and($ran())->toBeTrue()
            ->and($app->kit->accounts->twoFactorEnabled($user))->toBeTrue()
            ->and(nextRequest($thief))->toBeNull()
            ->and(nextRequest($owner))->toBeNull();
    });
})->with('drivers');

it('refuses to remove a passkey for a session a sign-out everywhere ended once the request began, and keeps the passkey', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $key = SoftwareAuthenticator::es256();
        $owner->confirm();
        $options = $owner->do(static fn (Accounts $a, Request $r) => $a->passkeyRegistrationOptions($r, $user));
        $passkey = $owner->do(static fn (Accounts $a, Request $r) => $a->registerPasskey($r, $user, credential($key, $key->register((string) Base64Url::decode($options['challenge']))), 'Laptop'));

        // A thief with a session, confirmed with the password it knows.
        $thief = new Visitor($app);
        $thief->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $thief->confirm();

        $ran = between($app, 'FROM passkeys WHERE user_id', static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r)));
        expect(refused(static fn () => $thief->do(static fn (Accounts $a, Request $r) => $a->removePasskey($r, $user, $passkey->id))))->toBe(['confirm' => [Accounts::SESSION_ENDED]])
            ->and($ran())->toBeTrue()
            ->and($app->kit->accounts->passkeys($user))->toHaveCount(1)
            ->and(nextRequest($thief))->toBeNull();
    });
})->with('drivers');

// An API token a signed-in session asks for is held to the credential epoch
// that session was held to when its request began. A revocation committing
// while the request runs ended the session, and a token held to the epoch
// after it would outlive it: a thief with a stolen cookie its owner had just
// confirmed keeping a way in through the owner's sign-out everywhere.

/** The ApiTokenRefused $work throws. */
function tokenRefused(\Closure $work): ApiTokenRefused
{
    try {
        $work();
    } catch (ApiTokenRefused $e) {
        return $e;
    }

    throw new \LogicException('No token was refused.');
}

it('refuses an API token to a session a sign-out everywhere ended once the request began, and makes none', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        // The owner confirms on the laptop, and a thief has a copy of its cookie.
        $owner->confirm();
        $thief = new Visitor($app);
        $thief->cookie = $owner->cookie;
        // The owner's phone and tablet, at other workers.
        $elsewhere = static function () use ($app): Visitor {
            $device = new Visitor(new MemberApp($app->orm->connection()));
            $device->app->now = $app->now;

            return $device;
        };
        $phone = $elsewhere();
        $phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $tablet = $elsewhere();

        $store = new ArrayApiTokens();
        $tokens = new ApiTokens($store, $app->kit->tokenHolders, $app->auth, $app->orm, ['' => ['notes:read']], clock: fn (): int => $app->now);
        $issue = static fn () => $thief->do(static fn (Accounts $a, Request $r) => $app->auth->actingAs(
            $user,
            static fn () => $a->issueApiToken($r, $user, $tokens, 'Backup', ['notes:read'], CarbonImmutable::createFromTimestampUTC($app->now)->addDays(30)),
        ));

        // A sign-in elsewhere, once the request has opened its session, moves
        // the auth epoch alone: the session stands, and so does its token.
        $ran = between($app, 'FROM auth_epochs WHERE user_id', static fn () => $tablet->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD)));
        $kept = $issue();
        expect($ran())->toBeTrue()
            ->and($tokens->authenticate($kept->plaintext))->not->toBeNull();

        // A sign-out everywhere, landing at the same moment, ended it: none.
        $ran = between($app, 'FROM auth_epochs WHERE user_id', static fn () => $phone->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r)));
        $refused = tokenRefused($issue);
        expect($ran())->toBeTrue()
            ->and($refused->choice)->toBe(ApiTokenChoice::Caller)
            ->and($refused->status)->toBe(403)
            ->and($refused->getMessage())->toBe(ApiTokens::SIGNED_OUT)
            ->and($store->tokens)->toHaveCount(1)
            ->and($tokens->authenticate($kept->plaintext))->toBeNull()
            ->and(nextRequest($thief))->toBeNull();
        $app->work();
        expect($app->links('api-token-created', 'ada@example.com'))->toHaveCount(1);
    });
})->with('drivers');

it('refuses an API token asked for with the password a reset elsewhere replaced once the request began, as the shop asks, and makes none', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $thief = member($app);
        $user = $thief->state()->user();
        \assert($user !== null);
        // The owner resets the password at another worker, its own account loaded.
        $worker = new MemberApp($app->orm->connection());
        $worker->now = $app->now;
        $owner = new Visitor($worker);
        $owner->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $worker->work();
        $token = token($worker->links('password-reset', 'ada@example.com')[0]);

        // The thief asks as the shop's form does, typing the password it
        // knows, which confirms the session in the request that issues.
        $store = new ArrayApiTokens();
        $tokens = new ApiTokens($store, $app->kit->tokenHolders, $app->auth, $app->orm, ['' => ['notes:read']], clock: fn (): int => $app->now);
        $ran = between($app, 'SET confirmed_at', static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->resetPassword($r, $token, 'the owner chose this one')));
        $refused = tokenRefused(static fn () => $thief->do(static function (Accounts $a, Request $r) use ($app, $user, $tokens): mixed {
            $a->confirmWithPassword($r, $user, PASSWORD);

            return $app->auth->actingAs($user, static fn () => $a->issueApiToken($r, $user, $tokens, 'Backup', ['notes:read'], CarbonImmutable::createFromTimestampUTC($app->now)->addDays(30)));
        }));
        expect($ran())->toBeTrue()
            ->and($refused->choice)->toBe(ApiTokenChoice::Caller)
            ->and($refused->getMessage())->toBe(ApiTokens::SIGNED_OUT)
            ->and($store->tokens)->toBe([])
            ->and(nextRequest($thief))->toBeNull()
            ->and(nextRequest($owner))->toBe('ada@example.com');
        $app->work();
        expect($app->links('api-token-created', 'ada@example.com'))->toBe([]);
    });
})->with('drivers');

it('ends at once the API token a request made when a sign-out everywhere lands just after it read the epoch', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $owner->confirm();
        $phone = new Visitor(new MemberApp($app->orm->connection()));
        $phone->app->now = $app->now;
        $phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        $tokens = new ApiTokens(new ArrayApiTokens(), $app->kit->tokenHolders, $app->auth, $app->orm, ['' => ['notes:read']], clock: fn (): int => $app->now);

        // Too late for the issue to see: the token is made, held to the epoch
        // the session was opened at, and the sign-out everywhere ends it.
        $ran = between($app, 'FROM credential_epochs WHERE user_id', static fn () => $phone->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r)));
        $issued = $owner->do(static fn (Accounts $a, Request $r) => $app->auth->actingAs(
            $user,
            static fn () => $a->issueApiToken($r, $user, $tokens, 'Backup', ['notes:read'], CarbonImmutable::createFromTimestampUTC($app->now)->addDays(30)),
        ));
        expect($ran())->toBeTrue()
            ->and($issued->token->epoch)->toBe('0')
            ->and($app->kit->credentials->of($user->authIdentifier()))->toBe('1')
            ->and($tokens->authenticate($issued->plaintext))->toBeNull()
            ->and(nextRequest($owner))->toBeNull();
    });
})->with('drivers');

it('refuses an API token for an account other than the one its session signs in', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $ada = member($app)->state()->user();
        \assert($ada !== null);
        $bob = member($app, 'bob@example.com');
        $bob->confirm();
        $store = new ArrayApiTokens();
        $tokens = new ApiTokens($store, $app->kit->tokenHolders, $app->auth, $app->orm, ['' => ['notes:read']], clock: fn (): int => $app->now);

        // Bob's session, confirmed, asking for one of Ada's: it was held to
        // no epoch of hers.
        $refused = tokenRefused(static fn () => $bob->do(static fn (Accounts $a, Request $r) => $app->auth->actingAs(
            $ada,
            static fn () => $a->issueApiToken($r, $ada, $tokens, 'Backup', ['notes:read'], CarbonImmutable::createFromTimestampUTC($app->now)->addDays(30)),
        )));
        expect($refused->choice)->toBe(ApiTokenChoice::Caller)
            ->and($refused->status)->toBe(403)
            ->and($store->tokens)->toBe([]);
    });
});

it('holds a session to the credential epoch and password its sign-in was checked against, whoever moved either and when', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $owner = member($app);
        $user = $owner->state()->user();
        \assert($user !== null);
        $sessions = $app->kit->sessions;

        // Checked, then overtaken: a sign-out everywhere commits before the
        // session is made, and it signs nobody in.
        $checked = $sessions->credentials($user);
        $owner->do(static fn (Accounts $a, Request $r) => $a->signOutEverywhere($r));
        $late = new Visitor($app);
        $late->do(static fn (Accounts $a, Request $r) => $sessions->signIn(SessionState::of($r), $user, $checked));
        expect(nextRequest($late))->toBeNull();

        // Checked now, it holds -- until the password changes, by the kit or
        // by the app's own hand.
        $now = new Visitor($app);
        $now->do(static fn (Accounts $a, Request $r) => $sessions->signIn(SessionState::of($r), $user, $sessions->credentials($user)));
        expect(nextRequest($now))->toBe('ada@example.com');
        $app->orm->connection()->execute('UPDATE members SET password_hash = ?', [password_hash('another passphrase', PASSWORD_BCRYPT, ['cost' => 4])]);
        expect(nextRequest($now))->toBeNull();

        // Checked against one account, it makes no session for another.
        $bob = member($app, 'bob@example.com')->state()->user();
        \assert($bob !== null);
        expect(static fn () => new Visitor($app)->do(static fn (Accounts $a, Request $r) => $sessions->signIn(SessionState::of($r), $bob, $sessions->credentials($user))))
            ->toThrow(\LogicException::class);
    });
})->with('drivers');

it('keeps every session of an account signed in when its password is rehashed at a sign-in', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $connection = $app->orm->connection();
        member($app);
        // Hashed below the policy, as a seeded account or an older policy
        // left it; signed in where the app keeps such a hash as it is.
        $connection->execute('UPDATE members SET password_hash = ?', [password_hash(PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        $before = $connection->select('SELECT password_hash FROM members')[0]['password_hash'];
        $seeded = static fn (string $hash): bool => (password_get_info($hash)['options']['cost'] ?? null) === 4;
        $laptop = new Visitor(new MemberApp($connection, keepHash: $seeded));
        $laptop->app->now = $app->now;
        $laptop->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));

        // Signed in where the policy holds: the hash is replaced, and the
        // laptop's session, held to the one replaced, goes on.
        $phone = new Visitor(new MemberApp($connection));
        $phone->app->now = $app->now;
        $phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));

        expect($connection->select('SELECT password_hash FROM members')[0]['password_hash'])->not->toBe($before)
            ->and(nextRequest($laptop))->toBe('ada@example.com')
            ->and(nextRequest($phone))->toBe('ada@example.com');
    });
})->with('drivers');

// A sign-in replaces a hash the policy has moved on from with its password
// hashed again, and used to write it whatever was stored by then. A reset
// committing between the sign-in's read and that write was overwritten by
// the old password, and the session the sign-in made was held to it: a
// thief with the old password undid the owner's reset. And of two sign-ins
// rehashing at once -- a double click on the form -- the second wrote over
// the first, whose sessions it had re-sealed were then held to a hash no
// longer stored: every other device was signed out. Each sign-in reads the
// account at a worker of its own, which loaded nothing before.

it('keeps a reset that lands while a sign-in rehashes the password it replaced, and refuses that sign-in', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $connection = $app->orm->connection();
        member($app);
        // Hashed below the policy, so a sign-in with the password replaces it.
        $connection->execute('UPDATE members SET password_hash = ?', [password_hash(PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        $stored = static fn (): string => (string) $connection->select('SELECT password_hash FROM members')[0]['password_hash'];

        $worker = new MemberApp($connection);
        $worker->now = $app->now;
        $owner = new Visitor($worker);
        $owner->do(static fn (Accounts $a, Request $r) => $a->requestPasswordReset($r, 'ada@example.com'));
        $worker->work();
        $token = token($worker->links('password-reset', 'ada@example.com')[0]);

        // The thief signs in with the old password; the owner's reset lands
        // once the thief's worker has read the account.
        $thief = new Visitor(new MemberApp($connection));
        $thief->app->now = $app->now;
        $ran = between($thief->app, 'FROM "members" WHERE "email"', static fn () => $owner->do(static fn (Accounts $a, Request $r) => $a->resetPassword($r, $token, 'the owner chose this one')));
        try {
            $thief->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
            $refusal = null;
        } catch (ValidationException $e) {
            $refusal = $e->errors;
        }

        expect($ran())->toBeTrue()
            ->and(password_verify('the owner chose this one', $stored()))->toBeTrue()
            ->and(nextRequest($thief))->toBeNull()
            ->and(nextRequest($owner))->toBe('ada@example.com')
            ->and($refusal)->toBe(['password' => [Accounts::NO_MATCH]]);

        // The old password signs in nowhere since.
        $later = new Visitor(new MemberApp($connection));
        $later->app->now = $app->now;
        expect(refused(static fn () => $later->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD))))->toBe(['password' => [Accounts::NO_MATCH]]);
    });
})->with('drivers');

it('keeps every other session of an account signed in when two sign-ins rehash its password at once', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $connection = $app->orm->connection();
        member($app);
        $connection->execute('UPDATE members SET password_hash = ?', [password_hash(PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        // Another device, signed in where the app keeps such a hash as it
        // is, so its session is held to it.
        $seeded = static fn (string $hash): bool => (password_get_info($hash)['options']['cost'] ?? null) === 4;
        $laptop = new Visitor(new MemberApp($connection, keepHash: $seeded));
        $laptop->app->now = $app->now;
        $laptop->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));

        // The form sent twice from one browser, to two workers: the second
        // rehashes and re-seals while the first has read the account and
        // not yet written.
        $first = new Visitor(new MemberApp($connection));
        $first->app->now = $app->now;
        $second = new Visitor(new MemberApp($connection));
        $second->app->now = $app->now;
        $second->cookie = $first->cookie;
        $ran = between($first->app, 'FROM "members" WHERE "email"', static fn () => $second->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD)));
        $first->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));

        $stored = (string) $connection->select('SELECT password_hash FROM members')[0]['password_hash'];
        expect($ran())->toBeTrue()
            ->and(password_get_info($stored)['options']['cost'] ?? null)->not->toBe(4)
            ->and(password_verify(PASSWORD, $stored))->toBeTrue()
            ->and(nextRequest($laptop))->toBe('ada@example.com')
            ->and(nextRequest($second))->toBe('ada@example.com')
            ->and(nextRequest($first))->toBe('ada@example.com');
    });
})->with('drivers');

// A session is opened by reading its row, then its account. A rehash
// stores its hash and re-seals the row in one transaction, and one that
// commits between the two reads left the row, as read, held to the hash
// the account no longer had: the device was signed out.
it('keeps a device signed in whose request opens its session while a sign-in elsewhere rehashes the password', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $connection = $app->orm->connection();
        member($app);
        $connection->execute('UPDATE members SET password_hash = ?', [password_hash(PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        $seeded = static fn (string $hash): bool => (password_get_info($hash)['options']['cost'] ?? null) === 4;
        $laptop = new Visitor(new MemberApp($connection, keepHash: $seeded));
        $laptop->app->now = $app->now;
        $laptop->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));

        // The laptop's next request, at a worker that has loaded nothing:
        // the phone's sign-in rehashes once that request has read its row.
        $next = new Visitor(new MemberApp($connection));
        $next->app->now = $app->now;
        $next->cookie = $laptop->cookie;
        $phone = new Visitor(new MemberApp($connection));
        $phone->app->now = $app->now;
        $ran = between($next->app, 'FROM sessions s LEFT JOIN', static fn () => $phone->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD)));
        $opened = $next->state();

        expect($opened->user()?->accountEmail())->toBe('ada@example.com')
            ->and($opened->replacement())->toBeNull()
            ->and($ran())->toBeTrue()
            ->and(nextRequest($laptop))->toBe('ada@example.com')
            ->and(nextRequest($phone))->toBe('ada@example.com');
    });
})->with('drivers');

it('adds the credentials column to a sessions table made before it, and signs nobody in on a row without them', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $connection = $app->orm->connection();
        $schema = new Schema($connection, Dialect::for($connection));
        $visitor = member($app);
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->dropColumn('credentials');
        });
        expect($schema->hasColumn('sessions', 'credentials'))->toBeFalse();

        Sessions::addCredentials($schema);
        Sessions::addCredentials($schema);
        // Made before sessions were held to anything: nobody's, as one a
        // revocation ended is, and the browser signs in again.
        expect($schema->hasColumn('sessions', 'credentials'))->toBeTrue()
            ->and(nextRequest($visitor))->toBeNull();
        $visitor->do(static fn (Accounts $a, Request $r) => $a->signIn($r, 'ada@example.com', PASSWORD));
        expect(nextRequest($visitor))->toBe('ada@example.com');
    });
});
