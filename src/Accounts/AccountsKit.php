<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Accounts\Impersonation\ImpersonationConfig;
use Tinvar\Accounts\Impersonation\Impersonations;
use Tinvar\Accounts\Passkeys\PasskeyChallenges;
use Tinvar\Accounts\Passkeys\PasskeyStore;
use Tinvar\Accounts\Passkeys\RelyingParty;
use Tinvar\Accounts\TwoFactor\TwoFactorStore;
use Tinvar\Api\TokenHolderLookup;
use Tinvar\Audit\AuditLog;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\UserLookup;
use Tinvar\Container\Container;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Throttle;
use Tinvar\Http\ThrottleStore;
use Tinvar\Identity\DatabaseEmailLinkStore;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\Realms;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Mail\Mailer;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Support\DevMode;
use Tinvar\Support\Encrypter;

/**
 * The accounts kit, put together: everything an application's Kernel needs
 * from it in one object, built from what only the application knows -- its
 * users, its emails, its queue and mailer, its throttle store and its URL.
 *
 *     $accounts = new AccountsKit(
 *         connection: $connection, key: $key, users: new Users($orm), mails: new AccountMails(),
 *         jobs: $jobs, mailer: $mailer, throttles: $signInStore, throttle: $throttle,
 *         origin: AccountLinks::origin($config->url, $config->servedAt), name: 'My app', auth: $auth,
 *     );
 *     $accounts->register($container);                 // Accounts, Sessions, the middleware's needs
 *     globalMiddleware: [StartSession::class]          // in the framework Kernel's arguments
 *
 * and `AccountsKit::migrate($schema, User::class)` in a migration makes its
 * tables.
 */
final readonly class AccountsKit
{
    public Accounts $accounts;
    public Sessions $sessions;
    public SessionCookie $cookie;
    public AuthEpochs $epochs;

    /** What API tokens are held to: moved on fewer things than the auth epoch (CredentialEpochs). */
    public CredentialEpochs $credentials;
    public EmailLinks $links;
    public AccountLinks $accountLinks;

    /** The application's users, as it gave them: what AcceptInvitationPage makes an account in. */
    public AccountUsers $users;

    /** The users as a signed-in session is found again, at their auth epoch: what SendTenantInvite reads an invitee by. */
    public UserLookup $lookup;

    /**
     * The users as an API token's holder is found again, at their credential
     * epoch: the lookup to hand ApiAccess (ADR 0159), so a token outlives an
     * ordinary sign-in and ends when every session does.
     */
    public TokenHolderLookup $tokenHolders;

    /** The sessions a handoff arrives into, on another of the app's hosts: the HandoffEndpoint's (ADR 0039). */
    public HandoffArrivals $arrivals;

    /** How passwords are hashed and checked: for an account the app makes itself, as from an invite. */
    public Passwords $passwords;
    public StartSession $startSession;

    /** The middleware a signed-in page is behind: the sign-in page, or a handoff on another of the app's hosts. */
    public RequireSignIn $requireSignIn;

    /** The middleware a page for sessions of two factors is behind (ADR 0184). */
    public RequireMultiFactor $requireMultiFactor;
    public AccountPaths $paths;

    /**
     * Staff acting as the app's users (ADR 0194), where the app lets them:
     * null unless the kit was given `impersonation:`.
     */
    public ?Impersonations $impersonations;

    /**
     * @param ThrottleStore $throttles where sign-in guesses are counted: one a flood of
     *        other keys cannot clear, so never APCu (SignInThrottle refuses it)
     * @param string|null $origin the https origin links are built on and passkeys are for
     *        (AccountLinks::origin()); null for an app that has none of its own, which
     *        makes and opens no account link, sends no account email and starts no
     *        passkey ceremony, and signs in by password (ADR 0162)
     * @param string $name the app's name: the passkey relying party's, and the issuer an
     *        authenticator app files codes under
     * @param array<string, int> $limits sign-in throttle limits by bucket over the
     *        defaults -- `sign-in`, `sign-in.address`, `sign-in.account`,
     *        `two-factor`, `two-factor.account`, `two-factor.daily` (ADR 0203),
     *        `email-link`, `email-link.recipient`; 0 turns one off
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param Realms|null $realms the app's hosts, where it has more than one (ADR 0039): a
     *        signed-in page on another of them starts a handoff rather than asking anyone to
     *        sign in there
     * @param (\Closure(string): bool)|null $keepHash whether a stored hash is kept as it is
     *        though the policy has moved on (Passwords): a seeded demo account's, hashed
     *        cheaply on purpose, say; none by default. A kept hash is left out of the cost
     *        the sign-in decoy copies, so its account is refused at its own cost, and timing
     *        tells it from an address with no account: keep only accounts whose existence is
     *        no secret
     * @param AuditLog|null $audit where every sign-in, failed and not, every sign-out and
     *        every change to how an account signs in is recorded, about that account
     *        (ADR 0156); nowhere unless given
     * @param Orm|null $orm the application's: StartSession runs each request as the tenant
     *        its session was switched to through it (Sessions::switchTenant(), ADR 0181), and
     *        refuses a session holding one where it has no tenancy registered
     * @param ImpersonationConfig|null $impersonation whether, and how, the app's staff may act as
     *        its users (ADR 0194): who may act as whom, from which panel, for how long; none
     *        unless given. Needs the `impersonation` column on sessions
     *        (Sessions::addImpersonation()) and the `impersonations` table
     *        (Impersonations::define()), which migrate() leaves out
     * @param PanelContext|null $panels the Kernel's, which an impersonation is started in the
     *        panel of: needed with $impersonation, and unused without it
     */
    public function __construct(
        Connection $connection,
        #[\SensitiveParameter]
        string $key,
        AccountUsers $users,
        AccountMails $mails,
        Dispatcher $jobs,
        Mailer $mailer,
        ThrottleStore $throttles,
        Throttle $throttle,
        ?string $origin,
        string $name,
        AuthContext $auth,
        ?CsrfTokens $csrf = null,
        string $cookieName = '__Host-app_session',
        AccountPaths $paths = new AccountPaths(),
        array $limits = [],
        ?\Closure $clock = null,
        ?Realms $realms = null,
        ?\Closure $keepHash = null,
        ?AuditLog $audit = null,
        ?Orm $orm = null,
        ?ImpersonationConfig $impersonation = null,
        ?PanelContext $panels = null,
    ) {
        $this->paths = $paths;
        $this->cookie = new SessionCookie($key, $cookieName, $clock);
        $this->epochs = new AuthEpochs($connection);
        $this->credentials = new CredentialEpochs($connection);
        $this->users = $users;
        $this->lookup = new AccountUserLookup($users, $this->epochs);
        $this->tokenHolders = new AccountTokenHolders($users, $this->credentials, $this->epochs);
        $this->sessions = new Sessions($connection, $this->cookie, $users, $this->epochs, $this->credentials, $key);
        $this->arrivals = new HandoffArrivals($this->sessions);
        $this->passwords = new Passwords($users->passwordHashes(...), $keepHash);
        $local = $origin !== null && str_starts_with($origin, 'http://');
        $this->links = EmailLinks::fromKey($key, new DatabaseEmailLinkStore($connection), $this->lookup, $clock, localHttp: $local);
        $this->accountLinks = new AccountLinks($this->links, $origin, $paths);
        $this->startSession = new StartSession($this->sessions, $auth, $csrf, $orm);
        $this->requireSignIn = new RequireSignIn($auth, $paths, $realms);
        $this->requireMultiFactor = new RequireMultiFactor($auth, $paths, $this->requireSignIn);
        if ($impersonation !== null && $panels === null) {
            throw new \LogicException('Impersonation is started from a panel, and the kit was given no PanelContext to ask which: give AccountsKit the Kernel\'s (panels:).');
        }
        $this->impersonations = $impersonation === null ? null : new Impersonations(
            connection: $connection,
            sessions: $this->sessions,
            users: $users,
            epochs: $this->epochs,
            auth: $auth,
            panels: $panels,
            config: $impersonation,
            paths: $paths,
            key: $key,
            realms: $realms,
            audit: $audit,
            orm: $orm,
        );

        $this->accounts = new Accounts(
            connection: $connection,
            users: $users,
            passwords: $this->passwords,
            sessions: $this->sessions,
            epochs: $this->epochs,
            credentials: $this->credentials,
            links: $this->accountLinks,
            jobs: $jobs,
            mailer: $mailer,
            mails: $mails,
            signInThrottle: new SignInThrottle(
                $throttles,
                $throttle,
                perAccount: $limits['sign-in'] ?? SignInThrottle::PER_ACCOUNT,
                perAddress: $limits['sign-in.address'] ?? SignInThrottle::PER_ADDRESS,
                accountCeiling: $limits['sign-in.account'] ?? SignInThrottle::ACCOUNT_CEILING,
            ),
            // A code is six digits: a million guesses, so fewer of them, and
            // a ceiling a day across every address (ADR 0203).
            secondFactorThrottle: new SignInThrottle(
                $throttles,
                $throttle,
                perAccount: $limits['two-factor'] ?? 5,
                perAddress: $limits['sign-in.address'] ?? SignInThrottle::PER_ADDRESS,
                accountCeiling: $limits['two-factor.account'] ?? 50,
                prefix: 'two-factor',
                dailyCeiling: $limits['two-factor.daily'] ?? SignInThrottle::DAILY_CEILING,
            ),
            linkThrottle: new LinkRequestThrottle(
                $throttles,
                $throttle,
                perAddress: $limits['email-link'] ?? LinkRequestThrottle::PER_ADDRESS,
                perRecipient: $limits['email-link.recipient'] ?? LinkRequestThrottle::PER_RECIPIENT,
            ),
            twoFactor: new TwoFactorStore($connection, new Encrypter($key), $clock),
            passkeys: new PasskeyStore($connection, $clock),
            challenges: new PasskeyChallenges($connection, $clock),
            relyingParty: $origin === null ? null : RelyingParty::forOrigin($origin, $name),
            key: $key,
            issuer: $name,
            audit: $audit,
            auth: $auth,
        );
    }

    /**
     * Every table the kit keeps, for an application's migration: sessions,
     * auth and credential epochs, emailed links, passkeys and their challenges, two-factor
     * secrets and recovery codes -- each user column a foreign key to
     * $userModel's table.
     *
     * @param class-string<Model> $userModel
     */
    public static function migrate(Schema $schema, string $userModel, bool $ifNotExists = false): void
    {
        Sessions::define($schema, $userModel, $ifNotExists);
        AuthEpochs::define($schema, $userModel, ifNotExists: $ifNotExists);
        CredentialEpochs::define($schema, $userModel, $ifNotExists);
        DatabaseEmailLinkStore::define($schema, ifNotExists: $ifNotExists);
        PasskeyStore::define($schema, $userModel, $ifNotExists);
        PasskeyChallenges::define($schema, $ifNotExists);
        TwoFactorStore::define($schema, $userModel, $ifNotExists);
    }

    /** The tables migrate() makes, last first, for a migration's down(). */
    public const array TABLES = ['recovery_codes', 'two_factor_secrets', 'passkey_challenges', 'passkeys', 'email_links', 'credential_epochs', 'auth_epochs', 'sessions'];

    /**
     * What the application's pages, actions and middleware are built with.
     * Its users and the lookup over them too -- the tenant pages'
     * AcceptInvitationPage and SendTenantInvite are built with them -- unless
     * the application registered its own already.
     */
    public function register(Container $container): Container
    {
        if (!$container->registered(AccountUsers::class)) {
            $container->instance(AccountUsers::class, $this->users);
        }
        if (!$container->registered(UserLookup::class)) {
            $container->instance(UserLookup::class, $this->lookup);
        }
        if ($this->impersonations !== null) {
            $container->instance(Impersonations::class, $this->impersonations);
        }

        return $container
            ->instance(Accounts::class, $this->accounts)
            ->instance(Sessions::class, $this->sessions)
            ->instance(Passwords::class, $this->passwords)
            ->instance(SessionCookie::class, $this->cookie)
            ->instance(AccountPaths::class, $this->paths)
            ->instance(AccountLinks::class, $this->accountLinks)
            ->instance(StartSession::class, $this->startSession)
            ->instance(RequireSignIn::class, $this->requireSignIn)
            ->instance(RequireMultiFactor::class, $this->requireMultiFactor);
    }

    /** The session id a request carries, or '': what every token is bound through. */
    public function sessionId(Request $request): string
    {
        return $this->cookie->of($request);
    }

    /**
     * The cookies a browser signed in as $user sends with $request, with no
     * password asked: for tests, through the application's signedIn(). Refused
     * wherever the environment says production. Signed in with $factors --
     * none unless given -- as a test of a page that asks for two hands in a
     * passkey, say.
     *
     * @return array<string, string>
     */
    public function signedIn(Authenticatable $user, Request $request, ?SessionFactors $factors = null): array
    {
        $marker = DevMode::productionMarker(array_filter(getenv(), \is_string(...)));
        if ($marker !== null) {
            throw new \LogicException("Signing in without a password is refused: {$marker}=production.");
        }
        if (!$user instanceof AccountUser) {
            throw new \InvalidArgumentException('The accounts kit signs in an AccountUser, and was handed ' . get_debug_type($user) . '.');
        }

        return [$this->cookie->name => $this->sessions->signedInCookie($user, $request, $factors)];
    }
}
