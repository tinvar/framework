<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Accounts\Passkeys\PasskeyChallenges;
use Tinvar\Accounts\Passkeys\PasskeyStore;
use Tinvar\Accounts\Passkeys\RelyingParty;
use Tinvar\Accounts\TwoFactor\TwoFactorStore;
use Tinvar\Api\TokenHolderLookup;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\UserLookup;
use Tinvar\Container\Container;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Throttle;
use Tinvar\Http\ThrottleStore;
use Tinvar\Identity\DatabaseEmailLinkStore;
use Tinvar\Identity\EmailLinks;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Mail\Mailer;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
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
    public UserLookup $lookup;

    /**
     * The users as an API token's holder is found again, at their credential
     * epoch: the lookup to hand ApiAccess (ADR 0159), so a token outlives an
     * ordinary sign-in and ends when every session does.
     */
    public TokenHolderLookup $tokenHolders;
    public StartSession $startSession;
    public AccountPaths $paths;

    /**
     * @param ThrottleStore $throttles where sign-in guesses are counted: one a flood of
     *        other keys cannot clear, so never APCu (SignInThrottle refuses it)
     * @param string $origin the https origin links are built on and passkeys are for
     *        (AccountLinks::origin())
     * @param string $name the app's name: the passkey relying party's, and the issuer an
     *        authenticator app files codes under
     * @param array<string, int> $limits sign-in throttle limits by bucket over the
     *        defaults -- `sign-in`, `sign-in.address`, `sign-in.account`,
     *        `two-factor`, `email-link`, `email-link.recipient`; 0 turns one off
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
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
        string $origin,
        string $name,
        AuthContext $auth,
        ?CsrfTokens $csrf = null,
        string $cookieName = '__Host-app_session',
        AccountPaths $paths = new AccountPaths(),
        array $limits = [],
        ?\Closure $clock = null,
    ) {
        $this->paths = $paths;
        $this->cookie = new SessionCookie($key, $cookieName, $clock);
        $this->epochs = new AuthEpochs($connection);
        $this->credentials = new CredentialEpochs($connection);
        $this->lookup = new AccountUserLookup($users, $this->epochs);
        $this->tokenHolders = new AccountTokenHolders($users, $this->credentials, $this->epochs);
        $this->sessions = new Sessions($connection, $this->cookie, $users, $this->epochs, $this->credentials);
        $local = str_starts_with($origin, 'http://');
        $this->links = EmailLinks::fromKey($key, new DatabaseEmailLinkStore($connection), $this->lookup, $clock, localHttp: $local);
        $this->accountLinks = new AccountLinks($this->links, $origin, $paths);
        $this->startSession = new StartSession($this->sessions, $auth, $csrf);

        $this->accounts = new Accounts(
            connection: $connection,
            users: $users,
            passwords: Passwords::for($users),
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
            // A code is six digits: a million guesses, so fewer of them.
            secondFactorThrottle: new SignInThrottle(
                $throttles,
                $throttle,
                perAccount: $limits['two-factor'] ?? 5,
                perAddress: $limits['sign-in.address'] ?? SignInThrottle::PER_ADDRESS,
                accountCeiling: $limits['two-factor.account'] ?? 50,
                prefix: 'two-factor',
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
            relyingParty: RelyingParty::forOrigin($origin, $name),
            key: $key,
            issuer: $name,
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

    /** What the application's pages, actions and middleware are built with. */
    public function register(Container $container): Container
    {
        return $container
            ->instance(Accounts::class, $this->accounts)
            ->instance(Sessions::class, $this->sessions)
            ->instance(SessionCookie::class, $this->cookie)
            ->instance(AccountPaths::class, $this->paths)
            ->instance(AccountLinks::class, $this->accountLinks)
            ->instance(StartSession::class, $this->startSession);
    }

    /** The session id a request carries, or '': what every token is bound through. */
    public function sessionId(Request $request): string
    {
        return $this->cookie->of($request);
    }

    /**
     * The cookies a browser signed in as $user sends with $request, with no
     * password asked: for tests, through the application's signedIn(). Refused
     * wherever the environment says production.
     *
     * @return array<string, string>
     */
    public function signedIn(Authenticatable $user, Request $request): array
    {
        $marker = DevMode::productionMarker(array_filter(getenv(), \is_string(...)));
        if ($marker !== null) {
            throw new \LogicException("Signing in without a password is refused: {$marker}=production.");
        }
        if (!$user instanceof AccountUser) {
            throw new \InvalidArgumentException('The accounts kit signs in an AccountUser, and was handed ' . get_debug_type($user) . '.');
        }

        return [$this->cookie->name => $this->sessions->signedInCookie($user, $request)];
    }
}
