<?php

declare(strict_types=1);

/**
 * Impersonation (ADR 0194): staff with the app's permission, in the panel
 * it names, act as a user of lesser rank -- on this host, or on another of
 * the app's through a grant -- and stop back into their own session; time-
 * boxed, never nested, ended by the user's auth epoch moving or the
 * impersonator's session ending, and refused every change to how the user
 * signs in. Audited at start and stop, and every request in between carries
 * the impersonator. On SQLite, Postgres and MySQL.
 */

namespace Tinvar\Tests\Accounts\Impersonating;

use Tinvar\Accounts\AccountUser;
use Tinvar\Accounts\Accounts;
use Tinvar\Accounts\AccountsKit;
use Tinvar\Accounts\Impersonation\ArriveImpersonating;
use Tinvar\Accounts\Impersonation\ImpersonationBannerData;
use Tinvar\Accounts\Impersonation\ImpersonationConfig;
use Tinvar\Accounts\Impersonation\ImpersonationRefusal;
use Tinvar\Accounts\Impersonation\ImpersonationRefused;
use Tinvar\Accounts\Impersonation\Impersonations;
use Tinvar\Accounts\Impersonation\RankedImpersonationPolicy;
use Tinvar\Accounts\SessionState;
use Tinvar\Accounts\Sessions;
use Tinvar\Api\ApiTokenRefused;
use Tinvar\Api\ApiTokens;
use Tinvar\Api\ArrayApiTokens;
use Tinvar\Api\ArrayTenantApiTokens;
use Tinvar\Api\TenantApiTokens;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\DatabaseNonceStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\HandoffCode;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Support\Signer;
use Tinvar\Tests\Accounts\Fixtures\Member;
use Tinvar\Tests\Accounts\Fixtures\MemberApp;
use Tinvar\Tests\Accounts\Fixtures\Members;
use Tinvar\Tests\Support\Fixtures\Databases;
use Tinvar\Validation\ValidationException;

require_once __DIR__ . '/Fixtures/SoftwareAuthenticator.php';
require_once __DIR__ . '/Fixtures/Members.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';

const PASSWORD = 'correct horse battery staple';

/** Ranks by address: root above staff above everyone else; staff and root may impersonate. */
function rank(AccountUser $user): int
{
    return match (explode('@', $user->accountEmail())[0]) {
        'root' => 3,
        'staff', 'peer' => 2,
        default => 1,
    };
}

/** Addresses whose permission to impersonate has been taken away. */
final class Revoked
{
    /** @var list<string> */
    public static array $emails = [];

    /** @var list<string> tenants in which nobody may impersonate */
    public static array $tenants = [];
}

function config(int $minutes = 30, bool $sameRank = false): ImpersonationConfig
{
    return new ImpersonationConfig(
        policy: new RankedImpersonationPolicy(
            permitted: static fn (AccountUser $user, string $tenant): bool => rank($user) >= 2 && !\in_array($user->accountEmail(), Revoked::$emails, true) && !\in_array($tenant, Revoked::$tenants, true),
            rank: rank(...),
            sameRank: $sameRank,
        ),
        panel: 'admin',
        minutes: $minutes,
    );
}

function realms(): Realms
{
    return new Realms(Realm::platform('https://app.example'), [Realm::platform('https://admin.app.example')]);
}

/** A browser of one, its every request through the kit's StartSession, as the Kernel runs it. */
final class Browser
{
    public string $cookie;

    public function __construct(public readonly MemberApp $app, public string $host = 'app.example')
    {
        $this->cookie = $app->kit->cookie->issue();
    }

    /**
     * Runs $work inside StartSession, in $panel where one is given, and
     * keeps the cookie a rotation set.
     *
     * @template T
     * @param \Closure(Request, AuthContext): T $work
     * @param array<string, string> $query
     * @return T
     */
    public function do(\Closure $work, ?string $panel = null, string $method = 'POST', string $path = '/_action/x', array $query = []): mixed
    {
        $request = new Request($method, $path, ['host' => $this->host], query: $query, cookies: [$this->app->kit->cookie->name => $this->cookie], rawQuery: http_build_query($query, '', '&', PHP_QUERY_RFC3986), remoteAddress: '203.0.113.5');
        $app = $this->app;
        $result = null;
        $state = null;
        try {
            $app->kit->startSession->handle($request, static function (Request $inner) use ($app, $work, $panel, &$result, &$state): Response {
                $state = SessionState::of($inner);
                $result = $panel === null ? $work($inner, $app->auth) : $app->panels->within($panel, static fn (): mixed => $work($inner, $app->auth));

                return Response::make(200, 'ok');
            });
        } finally {
            if ($state instanceof SessionState) {
                $this->cookie = $state->replacement() ?? $this->cookie;
            }
            $app->auth->reset();
        }

        return $result;
    }

    /** The session as a worker that has read nothing yet would open it. */
    public function state(): SessionState
    {
        $worker = new MemberApp($this->app->orm->connection(), impersonation: config(), realms: realms());
        $worker->now = $this->app->now;

        return $worker->kit->sessions->open(new Request('GET', '/', ['host' => $this->host], cookies: [$this->app->kit->cookie->name => $this->cookie]));
    }

    public function who(): ?string
    {
        return $this->state()->user()?->accountEmail();
    }

    public function user(): AccountUser
    {
        return $this->state()->user() ?? throw new \LogicException('Nobody is signed in.');
    }

    public function signIn(string $email): self
    {
        $accounts = $this->app->kit->accounts;
        $this->do(static fn (Request $r): mixed => $accounts->signIn($r, $email, PASSWORD));

        return $this;
    }
}

function impersonations(MemberApp $app): Impersonations
{
    return $app->kit->impersonations ?? throw new \LogicException('The kit was given no impersonation config.');
}

/** Makes an account for each address, every one with PASSWORD. */
function accounts(MemberApp $app, string ...$emails): void
{
    $users = new Members($app->orm);
    foreach ($emails as $email) {
        $users->create($email, ucfirst(explode('@', $email)[0]), $app->kit->passwords->hash(PASSWORD));
    }
}

function find(MemberApp $app, string $email): AccountUser
{
    return new Members($app->orm)->findByEmail($email) ?? throw new \LogicException("No account for {$email}.");
}

/**
 * @param \Closure(MemberApp): void $test
 */
function onDriver(string $driver, \Closure $test, ?ImpersonationConfig $config = null, bool $audit = true, ?\Tinvar\Orm\TenantSubtrees $tenancy = null): void
{
    $run = static function (PdoConnection $connection) use ($test, $config, $audit, $tenancy): void {
        $schema = new Schema($connection, Dialect::for($connection));
        Members::define($schema);
        AccountsKit::migrate($schema, Member::class);
        Impersonations::define($schema, Member::class);
        Sessions::addImpersonation($schema);
        $app = new MemberApp($connection, audit: $audit, tenancy: $tenancy, impersonation: $config ?? config(), realms: realms());
        accounts($app, 'staff@example.com', 'ada@example.com', 'root@example.com', 'peer@example.com');
        $test($app);
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
        $mode = $pdo->query('SELECT @@SESSION.sql_mode')?->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
        try {
            $run(new PdoConnection($pdo, 'accounts'));
        } finally {
            $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote(\is_string($mode) ? $mode : ''));
        }
    });
}

/** Starts impersonating $email from $staff's browser, in the admin panel; where the browser goes next. */
function start(Browser $staff, string $email, string $reason = 'Ticket 4411: their invoices look wrong', string $to = '/orders', ?string $origin = null, string $back = '/admin/users'): string
{
    $app = $staff->app;

    return $staff->do(static fn (Request $r): string => impersonations($app)->start($r, find($app, $email), $reason, $to, $origin, $back), panel: 'admin');
}

function stop(Browser $browser): string
{
    return $browser->do(static fn (Request $r): string => impersonations($browser->app)->stop($r));
}

function refusal(\Closure $work): ImpersonationRefusal
{
    try {
        $work();
    } catch (ImpersonationRefused $e) {
        return $e->refusal;
    }

    throw new \LogicException('It was not refused.');
}

/** @return list<array{string, ?string, ?string, ?string, array<string, mixed>}> event, actor, impersonator, subject, data */
function audit(MemberApp $app): array
{
    return array_map(
        static fn (array $row): array => [(string) $row['event'], $row['actor_id'] === null ? null : (string) $row['actor_id'], $row['impersonator_id'] === null ? null : (string) $row['impersonator_id'], $row['subject_id'] === null ? null : (string) $row['subject_id'], json_decode((string) ($row['data'] ?? 'null'), true) ?? []],
        $app->orm->connection()->select("SELECT event, actor_id, impersonator_id, subject_id, data FROM audit_entries WHERE event LIKE 'auth.impersonation%' ORDER BY sequence"),
    );
}

function count(MemberApp $app, string $table): int
{
    return (int) $app->orm->connection()->select("SELECT COUNT(*) AS n FROM {$table}")[0]['n'];
}

dataset('drivers', ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql'], 'mysql' => ['mysql']]);

it('turns the staff member\'s session into the user\'s, keeping who they are, and stops back into their own', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        $ada = new Browser($app)->signIn('ada@example.com');
        $staffId = $staff->user()->authIdentifier();
        $adaId = $ada->user()->authIdentifier();
        $before = clone $staff;
        $epoch = $app->kit->epochs->of($adaId);

        expect(start($staff, 'ada@example.com'))->toBe('/orders');

        // The browser is Ada's now, under a new id, with the staff member on it.
        $state = $staff->state();
        expect($state->user()?->accountEmail())->toBe('ada@example.com')
            ->and($state->impersonator())->toBe($staffId)
            ->and($state->impersonation()?->reason)->toBe('Ticket 4411: their invoices look wrong')
            ->and($state->impersonation()?->until->getTimestamp())->toBe($app->now + 1800)
            // The cookie it had signs nobody in: the staff session is parked, not reachable.
            ->and($before->who())->toBeNull()
            // Ada's own browser is untouched, and her epoch moved: what her tabs hold is redrawn.
            ->and($ada->who())->toBe('ada@example.com')
            ->and($ada->state()->impersonation())->toBeNull()
            ->and($app->kit->epochs->of($adaId))->not->toBe($epoch);

        // Every request in it carries the impersonator, as AuthContext hands it on.
        expect($staff->do(static fn (Request $r, AuthContext $auth): array => [$auth->id(), $auth->impersonator()]))->toBe([$adaId, $staffId]);

        // The banner says so.
        $banner = $staff->do(static fn (Request $r): ?ImpersonationBannerData => impersonations($app)->banner($r));
        expect($banner?->actingAs)->toBe('Ada')
            ->and($banner?->impersonator)->toBe('Staff')
            ->and($banner?->reason)->toBe('Ticket 4411: their invoices look wrong')
            ->and($ada->do(static fn (Request $r): ?ImpersonationBannerData => impersonations($app)->banner($r)))->toBeNull();

        $during = clone $staff;
        $epochDuring = $app->kit->epochs->of($adaId);
        // On one host nothing the browser holds tells the staff member from
        // script in Ada's pages: the parked session is dropped, and they sign
        // in again to go back to the page they started from.
        expect(stop($staff))->toBe('/sign-in?next=%2Fadmin%2Fusers');

        expect($staff->who())->toBeNull()
            ->and($staff->state()->impersonation())->toBeNull()
            ->and($during->who())->toBeNull()
            ->and((int) $app->orm->connection()->select('SELECT COUNT(*) AS n FROM sessions WHERE user_id = ?', [$staffId])[0]['n'])->toBe(0)
            ->and($ada->who())->toBe('ada@example.com')
            // Ada's epoch moves again: nothing minted while she was acted as counts after.
            ->and($app->kit->epochs->of($adaId))->not->toBe($epochDuring)
            ->and(count($app, Impersonations::TABLE))->toBe(0)
            ->and($staff->do(static fn (Request $r, AuthContext $auth): array => [$auth->id(), $auth->impersonator()]))->toBe([null, null]);

        // Both ends in the audit log: the start by the staff member, the stop by them as Ada.
        $entries = audit($app);
        expect($entries)->toHaveCount(2)
            ->and(array_slice($entries[0], 0, 4))->toBe(['auth.impersonation_started', $staffId, null, $adaId])
            ->and($entries[0][4])->toMatchArray(['reason' => 'Ticket 4411: their invoices look wrong', 'host' => 'app.example'])
            ->and(array_slice($entries[1], 0, 4))->toBe(['auth.impersonation_stopped', $adaId, $staffId, $adaId])
            ->and($entries[1][4])->toMatchArray(['reason' => 'Ticket 4411: their invoices look wrong', 'ended' => 'stopped'])
            ->and($entries[1][4]['impersonation'])->toBe($entries[0][4]['impersonation']);
    });
})->with('drivers');

it('refuses to start outside the named panel, without the permission, for a user of equal or higher rank, for oneself and without a reason, and makes nothing', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        $plain = new Browser($app)->signIn('ada@example.com');
        $cookie = $staff->cookie;
        $epoch = $app->kit->epochs->of(find($app, 'ada@example.com')->authIdentifier());

        expect(refusal(static fn () => $staff->do(static fn (Request $r): string => impersonations($app)->start($r, find($app, 'ada@example.com'), 'why', '/'))))->toBe(ImpersonationRefusal::WrongPanel)
            ->and(refusal(static fn () => $staff->do(static fn (Request $r): string => impersonations($app)->start($r, find($app, 'ada@example.com'), 'why', '/'), panel: 'app')))->toBe(ImpersonationRefusal::WrongPanel)
            ->and(refusal(static fn () => start($plain, 'staff@example.com')))->toBe(ImpersonationRefusal::NotPermitted)
            ->and(refusal(static fn () => start($staff, 'peer@example.com')))->toBe(ImpersonationRefusal::Outranked)
            ->and(refusal(static fn () => start($staff, 'root@example.com')))->toBe(ImpersonationRefusal::Outranked)
            ->and(refusal(static fn () => start($staff, 'staff@example.com')))->toBe(ImpersonationRefusal::Themselves)
            ->and(refusal(static fn () => start(new Browser($app), 'ada@example.com')))->toBe(ImpersonationRefusal::NotSignedIn)
            ->and(refusal(static fn () => start($staff, 'ada@example.com', origin: 'https://elsewhere.example')))->toBe(ImpersonationRefusal::UnknownHost);

        foreach (['', '   ', str_repeat('x', Impersonations::MAX_REASON + 1)] as $reason) {
            expect(static fn () => start($staff, 'ada@example.com', $reason))->toThrow(ValidationException::class);
        }

        // A request an API token signed in has no session to turn.
        expect(refusal(static fn () => $staff->do(static function (Request $r, AuthContext $auth) use ($app): string {
            $auth->login($auth->user(), $auth->epoch(), apiToken: 'token-1');

            return impersonations($app)->start($r, find($app, 'ada@example.com'), 'why', '/');
        }, panel: 'admin')))->toBe(ImpersonationRefusal::ApiToken);

        expect($staff->cookie)->toBe($cookie)
            ->and($staff->who())->toBe('staff@example.com')
            ->and(count($app, Impersonations::TABLE))->toBe(0)
            ->and(audit($app))->toBe([])
            ->and($app->kit->epochs->of(find($app, 'ada@example.com')->authIdentifier()))->toBe($epoch);
    });
})->with('drivers');

it('lets a peer be impersonated where the policy says so, and never anyone above', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        expect(refusal(static fn () => start($staff, 'root@example.com')))->toBe(ImpersonationRefusal::Outranked);
        start($staff, 'peer@example.com');
        expect($staff->who())->toBe('peer@example.com');
    }, config(sameRank: true));
});

it('never nests: an impersonation starts from the impersonator\'s own session alone', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $root = new Browser($app)->signIn('root@example.com');
        start($root, 'staff@example.com');
        expect($root->who())->toBe('staff@example.com')
            ->and(refusal(static fn () => start($root, 'ada@example.com')))->toBe(ImpersonationRefusal::AlreadyImpersonating)
            ->and($root->who())->toBe('staff@example.com')
            ->and(refusal(static fn () => stop(new Browser($app)->signIn('ada@example.com'))))->toBe(ImpersonationRefusal::NotImpersonating);
    });
});

it('ends by itself when its time is up, signing the browser out and moving the user\'s epoch', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        $adaId = find($app, 'ada@example.com')->authIdentifier();
        start($staff, 'ada@example.com');
        $epoch = $app->kit->epochs->of($adaId);

        $app->now += 5 * 60 - 1;
        expect($staff->who())->toBe('ada@example.com');
        $app->now += 1;
        expect($staff->do(static fn (Request $r, AuthContext $auth): ?string => $auth->id()))->toBeNull()
            ->and($staff->who())->toBeNull()
            ->and(count($app, Impersonations::TABLE))->toBe(0)
            ->and((int) $app->orm->connection()->select('SELECT COUNT(*) AS n FROM sessions')[0]['n'])->toBe(0)
            ->and($app->kit->epochs->of($adaId))->not->toBe($epoch);
    }, config(minutes: 5));
})->with('drivers');

it('ends when the user\'s auth epoch moves, and when the impersonator\'s own session ends', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        start($staff, 'ada@example.com');
        // Ada signs in elsewhere: her epoch moves, and the impersonation is over.
        new Browser($app)->signIn('ada@example.com');
        expect($staff->who())->toBeNull();

        $staff->signIn('staff@example.com');
        $laptop = new Browser($app)->signIn('staff@example.com');
        start($staff, 'ada@example.com');
        expect($staff->who())->toBe('ada@example.com');
        // The staff member signs out everywhere from another device: the parked session goes with the rest.
        $laptop->do(static fn (Request $r): mixed => $app->kit->accounts->signOutEverywhere($r));
        expect($staff->who())->toBeNull();
    });
})->with('drivers');

it('refuses every change to how the user signs in while impersonating them, and an API token of theirs or their organization\'s', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        start($staff, 'ada@example.com');
        $ada = find($app, 'ada@example.com');
        $changes = [
            static fn (Accounts $a, Request $r): mixed => $a->changePassword($r, $ada, PASSWORD, 'a new passphrase entirely'),
            static fn (Accounts $a, Request $r): mixed => $a->requestEmailChange($r, $ada, 'mine@evil.example', PASSWORD),
            static fn (Accounts $a, Request $r): mixed => $a->confirmEmailChange($r, 'any-token'),
            static fn (Accounts $a, Request $r): mixed => $a->confirmWithPassword($r, $ada, PASSWORD),
            static fn (Accounts $a, Request $r): mixed => $a->confirmWithCode($r, $ada, '123456'),
            static fn (Accounts $a, Request $r): mixed => $a->beginTwoFactor($r, $ada),
            static fn (Accounts $a, Request $r): mixed => $a->confirmTwoFactor($r, $ada, '123456'),
            static fn (Accounts $a, Request $r): mixed => $a->disableTwoFactor($r, $ada, PASSWORD),
            static fn (Accounts $a, Request $r): mixed => $a->regenerateRecoveryCodes($r, $ada, PASSWORD),
            static fn (Accounts $a, Request $r): mixed => $a->passkeyRegistrationOptions($r, $ada),
            static fn (Accounts $a, Request $r): mixed => $a->passkeyConfirmationOptions($r, $ada),
            static fn (Accounts $a, Request $r): mixed => $a->registerPasskey($r, $ada, '{}', 'Mine'),
            static fn (Accounts $a, Request $r): mixed => $a->removePasskey($r, $ada, 'any'),
            static fn (Accounts $a, Request $r): mixed => $a->signOutEverywhere($r),
            static fn (Accounts $a, Request $r): mixed => $a->issueApiToken($r, $ada, new ApiTokens(new ArrayApiTokens(), $app->kit->tokenHolders, $app->auth, $app->orm, ['' => ['notes:read']]), 'mine', [], null),
        ];
        $tenantTokens = new TenantApiTokens(new ArrayTenantApiTokens(), static fn (string $t): bool => true, $app->kit->tokenHolders, $app->auth, $app->orm, ['' => ['notes:read']]);
        $changes[] = static fn (Accounts $a, Request $r): mixed => $app->orm->actAs('acme', static fn () => $a->issueTenantApiToken($r, $ada, $tenantTokens, 'Sync', ['notes:read'], null));
        foreach ($changes as $change) {
            expect(refusal(static fn () => $staff->do(static fn (Request $r): mixed => $change($app->kit->accounts, $r))))->toBe(ImpersonationRefusal::WhileImpersonating);
        }

        // ApiTokens itself refuses, from what AuthContext was handed.
        expect(static fn () => $staff->do(static fn (Request $r, AuthContext $auth): mixed => new ApiTokens(new ArrayApiTokens(), $app->kit->tokenHolders, $auth, $app->orm, ['' => ['notes:read']])->issue('mine', [], null)))
            ->toThrow(ApiTokenRefused::class);
        // And so does TenantApiTokens: an organization's token would outlive the impersonation too (ADR 0193).
        expect(static fn () => $staff->do(static fn (Request $r): mixed => $app->orm->actAs('acme', static fn () => $tenantTokens->issue('Sync', ['notes:read']))))
            ->toThrow(ApiTokenRefused::class, 'impersonating');

        expect($staff->who())->toBe('ada@example.com');
    });
})->with('drivers');

it('signs out of an impersonation and the parked session both', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        start($staff, 'ada@example.com');
        $staffId = find($app, 'staff@example.com')->authIdentifier();

        expect($staff->state()->impersonator())->toBe($staffId);

        $staff->do(static fn (Request $r): mixed => $app->kit->accounts->signOut($r));
        expect($staff->who())->toBeNull()
            ->and(count($app, Impersonations::TABLE))->toBe(0)
            ->and((int) $app->orm->connection()->select('SELECT COUNT(*) AS n FROM sessions WHERE user_id = ?', [$staffId])[0]['n'])->toBe(0);
    });
})->with('drivers');

it('starts on one host and arrives on another by a grant the impersonator\'s own session there redeems once', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        // Signed in on the platform host, then handed to the admin host.
        $platform = new Browser($app, 'app.example')->signIn('staff@example.com');
        $admin = new Browser($app, 'admin.app.example')->signIn('staff@example.com');
        $staffId = find($app, 'staff@example.com')->authIdentifier();

        $url = start($admin, 'ada@example.com', to: '/orders', origin: 'https://app.example', back: '/users/7');
        expect($url)->toStartWith('https://app.example/impersonate?' . Impersonations::PARAMETER . '=')
            // Nothing has changed on either host yet.
            ->and($admin->who())->toBe('staff@example.com')
            ->and($platform->who())->toBe('staff@example.com');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $grant = (string) $query[Impersonations::PARAMETER];
        $arrive = static fn (Browser $b, string $g): string => $b->do(static fn (Request $r): string => impersonations($app)->arrive($r, $g), method: 'GET', path: '/impersonate');

        // Not on the host it names, not by anyone else's session, not tampered with.
        $ada = new Browser($app, 'app.example')->signIn('ada@example.com');
        expect(refusal(static fn () => $arrive(new Browser($app, 'admin.app.example')->signIn('staff@example.com'), $grant)))->toBe(ImpersonationRefusal::GrantRefused)
            ->and(refusal(static fn () => $arrive($ada, $grant)))->toBe(ImpersonationRefusal::GrantRefused)
            ->and(refusal(static fn () => $arrive($platform, $grant . 'x')))->toBe(ImpersonationRefusal::GrantRefused)
            ->and(refusal(static fn () => $arrive(new Browser($app, 'app.example'), $grant)))->toBe(ImpersonationRefusal::NotSignedIn);

        expect($arrive($platform, $grant))->toBe('/orders')
            ->and($platform->who())->toBe('ada@example.com')
            ->and($platform->state()->impersonator())->toBe($staffId)
            // The admin host's session is the staff member's still.
            ->and($admin->who())->toBe('staff@example.com');

        // Once.
        $again = new Browser($app, 'app.example')->signIn('staff@example.com');
        expect(refusal(static fn () => $arrive($again, $grant)))->toBe(ImpersonationRefusal::GrantRefused);

        // Stopping sends the browser back to the panel it started from, on
        // its own host, where its session was never touched; this host's
        // parked one is dropped.
        expect(stop($platform))->toBe('https://admin.app.example/users/7')
            ->and($platform->who())->toBeNull()
            ->and($admin->who())->toBe('staff@example.com')
            ->and(array_column(audit($app), 0))->toBe(['auth.impersonation_started', 'auth.impersonation_stopped']);
    });
})->with('drivers');

it('refuses a grant that waited too long, or whose impersonator lost the permission meanwhile', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $platform = new Browser($app, 'app.example')->signIn('staff@example.com');
        $admin = new Browser($app, 'admin.app.example')->signIn('staff@example.com');
        $grant = static function () use ($admin): string {
            parse_str((string) parse_url(start($admin, 'ada@example.com', origin: 'https://app.example'), PHP_URL_QUERY), $query);

            return (string) $query[Impersonations::PARAMETER];
        };
        $arrive = static fn (string $g): string => $platform->do(static fn (Request $r): string => impersonations($app)->arrive($r, $g), method: 'GET', path: '/impersonate');

        $late = $grant();
        $app->now += Impersonations::ARRIVAL_WINDOW + 1;
        expect(refusal(static fn () => $arrive($late)))->toBe(ImpersonationRefusal::GrantRefused);

        $demoted = $grant();
        Revoked::$emails = ['staff@example.com'];
        try {
            expect(refusal(static fn () => $arrive($demoted)))->toBe(ImpersonationRefusal::NotPermitted)
                ->and($platform->who())->toBe('staff@example.com');
        } finally {
            Revoked::$emails = [];
        }
    });
});

it('arrives through its middleware with a redirect that sends no Referer and is kept by no cache', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $platform = new Browser($app, 'app.example')->signIn('staff@example.com');
        $admin = new Browser($app, 'admin.app.example')->signIn('staff@example.com');
        parse_str((string) parse_url(start($admin, 'ada@example.com', to: '/orders?page=2', origin: 'https://app.example'), PHP_URL_QUERY), $query);
        $middleware = new ArriveImpersonating(impersonations($app));

        $response = $platform->do(static fn (Request $r): Response => $middleware->handle($r, static fn (): Response => Response::make(200, 'never drawn')), method: 'GET', path: '/impersonate', query: $query);
        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/orders?page=2')
            ->and($response->header('referrer-policy'))->toBe('no-referrer')
            ->and($response->header('cache-control'))->toBe('no-store')
            ->and($platform->who())->toBe('ada@example.com')
            ->and(refusal(static fn () => $platform->do(static fn (Request $r): Response => $middleware->handle($r, static fn (): Response => Response::make(200, '')), method: 'GET', path: '/impersonate')))->toBe(ImpersonationRefusal::GrantRefused);
    });
});

it('never hands an impersonated session to another host', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $connection = $app->orm->connection();
        DatabaseNonceStore::define(new Schema($connection, Dialect::for($connection)), 'handoff_nonces');
        $kit = $app->kit;
        $endpoint = new HandoffEndpoint(
            realms: realms(),
            signer: new Signer(MemberApp::KEY, HandoffCode::PURPOSE),
            nonces: new DatabaseNonceStore($connection, 'handoff_nonces', durable: true),
            sessionId: static fn (Request $r): string => $kit->cookie->of($r),
            auth: $app->auth,
            orm: $app->orm,
            users: $kit->lookup,
            sessions: $kit->arrivals,
            signIn: static fn (string $next): Response => Response::redirect('/sign-in'),
            clock: static fn (): int => $app->now,
        );
        $query = ['origin' => 'https://admin.app.example', 'state' => str_repeat('a', 32), 'challenge' => str_repeat('b', 43), 'return' => '/'];
        $authorize = static fn (Browser $b): Response => $b->do(static fn (Request $r): Response => $endpoint->handle($r), method: 'GET', path: HandoffEndpoint::AUTHORIZE, query: $query);

        $staff = new Browser($app)->signIn('staff@example.com');
        expect($authorize($staff)->header('location'))->toStartWith('https://admin.app.example/_handoff/callback?');

        start($staff, 'ada@example.com');
        expect(static fn () => $authorize($staff))->toThrow(fn (HttpException $e) => expect($e->status)->toBe(403)->and($e->code_)->toBe('handoff_refused'));
    });
});

it('signs in as before on a sessions table with no impersonation column, and adds it and takes it away again', function () {
    $connection = PdoConnection::sqlite(':memory:', 'accounts');
    $schema = new Schema($connection, Dialect::for($connection));
    Members::define($schema);
    AccountsKit::migrate($schema, Member::class);
    $app = new MemberApp($connection);
    accounts($app, 'ada@example.com');

    expect(new Browser($app)->signIn('ada@example.com')->who())->toBe('ada@example.com')
        ->and($app->kit->impersonations)->toBeNull();

    expect($schema->hasColumn('sessions', 'impersonation'))->toBeFalse();
    Sessions::addImpersonation($schema);
    Sessions::addImpersonation($schema);
    expect($schema->hasColumn('sessions', 'impersonation'))->toBeTrue();
    Sessions::dropImpersonation($schema);
    Sessions::dropImpersonation($schema);
    expect($schema->hasColumn('sessions', 'impersonation'))->toBeFalse();
});

it('holds its configuration to a panel, a time box and a page path, and a kit given one to a panel context', function () {
    $policy = config()->policy;
    expect(static fn () => new ImpersonationConfig($policy, ''))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => new ImpersonationConfig($policy, 'admin', minutes: 0))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => new ImpersonationConfig($policy, 'admin', minutes: ImpersonationConfig::MAX_MINUTES + 1))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => new ImpersonationConfig($policy, 'admin', arrival: 'https://evil.example/x'))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => new ImpersonationConfig($policy, 'admin', arrival: '//evil.example'))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => new ImpersonationConfig($policy, 'admin', arrival: '/_action/x'))->toThrow(\InvalidArgumentException::class)
        ->and(new ImpersonationConfig($policy, 'admin', minutes: 60, arrival: '/admin/impersonate')->minutes)->toBe(60);

    $connection = PdoConnection::sqlite(':memory:', 'accounts');
    expect(static fn () => new MemberApp($connection, impersonation: config(), panelContext: false))->toThrow(\LogicException::class, 'PanelContext');
});

// What a review of ADR 0194 found.

it('ends an impersonation whose time is up though its browser never comes back, moving the user\'s epoch, from the scheduled task', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        $adaId = find($app, 'ada@example.com')->authIdentifier();
        $staffId = find($app, 'staff@example.com')->authIdentifier();
        start($staff, 'ada@example.com');
        $during = $app->kit->epochs->of($adaId);

        $app->now += 29 * 60;
        expect(impersonations($app)->endExpired())->toBe(0)
            ->and($app->kit->epochs->of($adaId))->toBe($during);

        // Its time is up; nobody opens the staff browser.
        $app->now += 2 * 60;
        new \Tinvar\Accounts\Impersonation\EndImpersonations()(impersonations($app));
        expect($app->kit->epochs->of($adaId))->not->toBe($during)
            ->and(count($app, Impersonations::TABLE))->toBe(0)
            ->and((int) $app->orm->connection()->select('SELECT COUNT(*) AS n FROM sessions WHERE user_id IN (?, ?)', [$adaId, $staffId])[0]['n'])->toBe(0)
            ->and($staff->who())->toBeNull();
        $stopped = audit($app)[1];
        expect([$stopped[0], $stopped[3]])->toBe(['auth.impersonation_stopped', $adaId])
            ->and($stopped[4])->toMatchArray(['ended' => 'expired', 'impersonator' => $staffId]);
    });
})->with('drivers');

it('moves the user\'s epoch when another start sweeps an impersonation whose time is up', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        $root = new Browser($app)->signIn('root@example.com');
        $adaId = find($app, 'ada@example.com')->authIdentifier();
        start($staff, 'ada@example.com');
        $during = $app->kit->epochs->of($adaId);

        $app->now += 31 * 60;
        $root->signIn('root@example.com');
        start($root, 'peer@example.com');
        expect($app->kit->epochs->of($adaId))->not->toBe($during)
            ->and((int) $app->orm->connection()->select('SELECT COUNT(*) AS n FROM impersonations WHERE user_id = ?', [$adaId])[0]['n'])->toBe(0);
    });
});

it('never gives the impersonator\'s session to whoever holds the impersonated cookie', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        start($staff, 'ada@example.com');
        $thief = new Browser($app);
        $thief->cookie = $staff->cookie;

        stop($thief);
        expect($thief->who())->toBeNull()
            ->and($staff->who())->toBeNull()
            ->and((int) $app->orm->connection()->select('SELECT COUNT(*) AS n FROM sessions WHERE user_id = ?', [find($app, 'staff@example.com')->authIdentifier()])[0]['n'])->toBe(0);
    });
});

it('binds what is bound to the user\'s epoch during an impersonation to that impersonation, and jobs to the user\'s own epoch', function () {
    onDriver('sqlite', function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        $ada = new Browser($app)->signIn('ada@example.com');
        start($staff, 'ada@example.com');
        $impersonation = $staff->state()->impersonation()?->id;

        $own = $ada->do(static fn (Request $r, AuthContext $auth): array => [$auth->id(), $auth->epoch(), $auth->accountEpoch(), $auth->impersonation()]);
        $acted = $staff->do(static fn (Request $r, AuthContext $auth): array => [$auth->id(), $auth->epoch(), $auth->accountEpoch(), $auth->impersonation()]);
        expect($acted[0])->toBe($own[0])
            ->and($acted[1])->not->toBe($own[1])
            ->and($acted[1])->toBe(AuthContext::boundEpoch($own[1], $impersonation))
            ->and($acted[2])->toBe($own[2])
            ->and($acted[3])->toBe($impersonation)
            ->and($own[3])->toBeNull();

        // A cached answer for one is no answer for the other: the key takes the epoch.
        $signer = new Signer(MemberApp::KEY, 'route');
        expect(\Tinvar\Render\TokenClaims::hashAuth($signer, $acted[0], $acted[1]))->not->toBe(\Tinvar\Render\TokenClaims::hashAuth($signer, $own[0], $own[1]));

        // Inside actingAs() there is no impersonation, and the epoch is the one given.
        expect($staff->do(static fn (Request $r, AuthContext $auth): array => $auth->actingAs($auth->user(), static fn (): array => [$auth->epoch(), $auth->impersonation()], '7')))->toBe(['7', null]);
    });
});

it('lands the target in the tenant the impersonation was started in, not the impersonator\'s own', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        // Staff with no tenant of their own start it acting in acme, as an
        // app's staff panel does for one tenant's member: the target's session
        // runs in acme, and the staff's parked session keeps none.
        $staff = new Browser($app)->signIn('staff@example.com');
        expect($staff->state()->tenant() ?? '')->toBe('');

        $staff->do(static fn (Request $r): string => $app->orm->actAs('acme', static fn (): string => impersonations($app)->start($r, find($app, 'ada@example.com'), 'Ticket 4411: their invoices look wrong', '/orders', null, '/admin/users')), panel: 'admin');

        expect($staff->who())->toBe('ada@example.com')
            ->and($staff->state()->tenant())->toBe('acme');
        $parked = $app->orm->connection()->select("SELECT tenant FROM sessions WHERE user_id = ? AND impersonation IS NULL", [find($app, 'staff@example.com')->authIdentifier()]);
        expect(array_column($parked, 'tenant'))->toBe([null]);
    }, tenancy: \Tinvar\Tests\Orm\Fixtures\Tenancy::flat());
})->with('drivers');

it('records an expired impersonation\'s end in its own tenant\'s log, whatever tenant the sweep runs in', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        $staff->do(static fn (Request $r): string => $app->orm->actAs('acme', static fn (): string => impersonations($app)->start($r, find($app, 'ada@example.com'), 'Ticket 4411: their invoices look wrong', '/orders', null, '/admin/users')), panel: 'admin');

        // Its time is up; the next start, acting in globex, sweeps it first.
        $app->now += 31 * 60;
        $app->orm->actAs('globex', static fn (): int => impersonations($app)->endExpired());

        $stops = $app->orm->connection()->select("SELECT tenant FROM audit_entries WHERE event = 'auth.impersonation_stopped'");
        expect(array_column($stops, 'tenant'))->toBe(['acme']);
    }, tenancy: \Tinvar\Tests\Orm\Fixtures\Tenancy::flat());
})->with('drivers');

it('holds an impersonation to the tenant it was started in: asked of the policy, pinned on the session, and never switched', function (string $driver) {
    onDriver($driver, function (MemberApp $app): void {
        $staff = new Browser($app)->signIn('staff@example.com');
        $staff->do(static fn (Request $r): ?string => $app->kit->sessions->switchTenant(SessionState::of($r), SessionState::of($r)->user() ?? throw new \LogicException(), 'acme'));

        Revoked::$tenants = ['acme'];
        try {
            expect(refusal(static fn () => start($staff, 'ada@example.com')))->toBe(ImpersonationRefusal::NotPermitted);
        } finally {
            Revoked::$tenants = [];
        }

        start($staff, 'ada@example.com');
        expect($staff->who())->toBe('ada@example.com')
            ->and($staff->state()->tenant())->toBe('acme')
            ->and(refusal(static fn () => $staff->do(static fn (Request $r): ?string => $app->kit->sessions->switchTenant(SessionState::of($r), SessionState::of($r)->user() ?? throw new \LogicException(), 'globex'))))->toBe(ImpersonationRefusal::WhileImpersonating)
            ->and($staff->state()->tenant())->toBe('acme');
    }, tenancy: \Tinvar\Tests\Orm\Fixtures\Tenancy::flat());
})->with('drivers');
