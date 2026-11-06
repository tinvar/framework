<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Http\HostName;
use Tinvar\Http\Request;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Routing\HostMatch;
use Tinvar\Support\Signer;

/**
 * Server-side sessions, in the `sessions` table, keyed by the signed cookie.
 *
 * The cookie says WHICH session; the row says whose it is. Signing stops a
 * client inventing an id, the row lets the server end a session -- sign-out
 * deletes it, and a cookie whose row is gone signs nobody in -- and the row
 * is keyed by a hash of the id, so a copy of the table is not a list of
 * cookies that sign in as their owners.
 *
 * Only a signed-in session has a row, or one half-way through signing in,
 * waiting on its second factor. A signed-out one is the cookie alone: its
 * CSRF and fragment tokens bind to the cookie's id, and a crawler or a
 * health check writes nothing.
 *
 * Every change of who a session belongs to rotates the id -- a sign-in, a
 * sign-out, a second factor passed, a handoff's arrival -- so an id an
 * attacker planted or saw before the change never carries the privilege
 * after it. And every session belongs to the host it was made on, its
 * realm, and signs nobody in on any other (ADR 0039): a handoff brings its
 * user to another of the app's hosts under a session of that host's own
 * (arrive(), HandoffArrivals).
 *
 * And every session is held to what its sign-in was checked against
 * (Credentials): the account's credential epoch, which every revocation
 * moves -- a sign-out everywhere, a new password or address, two-factor
 * turned off, a passkey removed -- and its password hash. A revocation
 * deletes the rows it sees; a sign-in checked before it whose row came
 * after is one it could not see, and signs nobody in from its next request
 * on, as if deleted (ADR 0165).
 *
 * Generalized from the example shop's, which signs its customers in
 * through it (ADR 0162).
 */
final readonly class Sessions
{
    /**
     * last_seen_at is written at most this often per session, in seconds.
     * It says a session is in use, and nothing else reads it: a session ends
     * at its cookie's expiry or at sign-out, never for being idle.
     */
    public const int TOUCH_EVERY = 300;

    /** Expired rows deleted per sign-in, at most: rows come from sign-ins alone. */
    public const int SWEEP_BATCH = 500;

    /** How long a password that was right waits for its second factor: ten minutes. */
    public const int SECOND_FACTOR_LIFETIME = 600;

    /**
     * How long the owner's proof that it is them -- a password typed again,
     * a passkey or an authenticator code -- lets the session change how the
     * account signs in: five minutes (Accounts::CONFIRMATION_LIFETIME).
     */
    public const int CONFIRMATION_LIFETIME = 300;

    /** What a row's credentials are sealed under, with the app key. */
    private const string CREDENTIALS_PURPOSE = 'session-credentials';

    private Signer $seals;

    public function __construct(
        private Connection $connection,
        private SessionCookie $cookie,
        private AccountUsers $users,
        private AuthEpochs $epochs,
        private CredentialEpochs $credentials,
        #[\SensitiveParameter]
        string $key,
    ) {
        $this->seals = new Signer($key, self::CREDENTIALS_PURPOSE);
    }

    /** @param class-string<Model> $userModel */
    public static function define(Schema $schema, string $userModel, bool $ifNotExists = false): void
    {
        $schema->create('sessions', static function (Table $sessions) use ($userModel): void {
            // A hash of the id, never the id.
            $sessions->string('id', 64)->primary();
            $sessions->foreign($userModel, 'user_id')->cascadeOnDelete();
            $sessions->string('realm', 255);
            $sessions->enum('stage', SessionStage::class);
            // Unix seconds, compared on every request and never shown.
            $sessions->bigInteger('created_at');
            $sessions->bigInteger('last_seen_at');
            $sessions->bigInteger('expires_at');
            // When the owner last proved it was them on this session; null
            // until they do, and never carried to the next id.
            $sessions->bigInteger('confirmed_at')->nullable();
            // The handoff that started the session on this host (ADR 0039):
            // its code's redemption (Arrival::$redemption), so a replay of
            // the code ends it. Null for every session a sign-in made.
            $sessions->string('handoff_redemption', 64)->nullable();
            // What its sign-in was checked against, sealed under the app key
            // (seal()): the account's credential epoch and password hash
            // then. Null on a row made before sessions were held to them,
            // which signs nobody in.
            $sessions->string('credentials', 64)->nullable();
            $sessions->index(['user_id'], 'sessions_user');
            $sessions->index(['expires_at'], 'sessions_expiry');
            $sessions->index(['handoff_redemption'], 'sessions_handoff');
        }, $ifNotExists);
    }

    /**
     * Adds `confirmed_at` to a sessions table define() made before it had
     * one; a table that has it is left as it is.
     */
    public static function addConfirmedAt(Schema $schema): void
    {
        if ($schema->hasColumn('sessions', 'confirmed_at')) {
            return;
        }
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->bigInteger('confirmed_at')->nullable();
        });
    }

    /**
     * Adds `handoff_redemption` to a sessions table define() made before it
     * had one, for an app that starts handing its sessions to another of its
     * hosts; a table that has it is left as it is. Signing in needs no such
     * column: an app with one host never writes it.
     */
    public static function addHandoffRedemption(Schema $schema): void
    {
        if ($schema->hasColumn('sessions', 'handoff_redemption')) {
            return;
        }
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->string('handoff_redemption', 64)->nullable();
            $sessions->index(['handoff_redemption'], 'sessions_handoff');
        });
    }

    /**
     * Adds `credentials` to a sessions table define() made before it had
     * one; a table that has it is left as it is. No row there has any, so
     * each signs nobody in, and its browser signs in once more (ADR 0165):
     * nothing says what the sign-in that made it was checked against.
     */
    public static function addCredentials(Schema $schema): void
    {
        if ($schema->hasColumn('sessions', 'credentials')) {
            return;
        }
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->string('credentials', 64)->nullable();
        });
    }

    public function cookie(): SessionCookie
    {
        return $this->cookie;
    }

    /**
     * What a sign-in of $user is checked against, read now: the account's
     * credential epoch, and its password hash -- or $passwordHash, where the
     * password checked has been stored again since, as by a rehash. Read
     * before the check and handed to the sign-in after it, so a revocation
     * committed between the two ends the session the sign-in makes, wherever
     * its row is.
     */
    public function credentials(AccountUser $user, #[\SensitiveParameter] ?string $passwordHash = null): Credentials
    {
        $id = $user->authIdentifier();

        return new Credentials($id, $this->credentials->of($id), $passwordHash ?? $user->accountPasswordHash());
    }

    /**
     * The session a request carries, and whose it is. A request without a
     * valid cookie has no session at all: the application's Kernel gives
     * every request one before anything reads it.
     */
    public function open(Request $request): SessionState
    {
        $realm = self::realmOf($request);
        $cookie = $request->cookie($this->cookie->name);
        $id = $this->cookie->id($cookie);
        if ($id === null || $cookie === null) {
            return new SessionState('', $realm);
        }

        $now = $this->cookie->now();
        $row = $this->connection->select(
            'SELECT s.user_id, s.realm, s.stage, s.last_seen_at, s.expires_at, s.confirmed_at, s.credentials, c.epoch AS credential_epoch '
            . 'FROM sessions s LEFT JOIN ' . CredentialEpochs::TABLE . ' c ON c.user_id = s.user_id WHERE s.id = ?',
            [self::hash($id)],
        )[0] ?? null;

        // No row, expired, or made on another host: as far as this host
        // knows, nobody's.
        if ($row === null || (int) $row['expires_at'] <= $now || !hash_equals((string) $row['realm'], $realm)) {
            return $this->ended($cookie, $id, $realm);
        }

        // Held to what its sign-in was checked against: a revocation since
        // moved the credential epoch, or a new password replaced the hash,
        // and ended it whether or not its row was there to delete.
        $user = \is_scalar($row['user_id']) ? $this->users->find((string) $row['user_id']) : null;
        if ($user === null) {
            return $this->ended($cookie, $id, $realm);
        }
        $credentialEpoch = self::epoch($row['credential_epoch'] ?? null);
        if (!$this->holds($row['credentials'] ?? null, new Credentials($user->authIdentifier(), $credentialEpoch, $user->accountPasswordHash()))) {
            // A rehash stores its hash and holds the account's sessions to it
            // in one transaction (rehashed()): one that committed between the
            // row's read and the account's left the row, as read, held to the
            // hash replaced. Read again, it is held to the one loaded; a row a
            // revocation deleted, or held to another password, is not.
            $again = $this->connection->select(
                'SELECT s.credentials, c.epoch AS credential_epoch FROM sessions s LEFT JOIN ' . CredentialEpochs::TABLE . ' c ON c.user_id = s.user_id WHERE s.id = ?',
                [self::hash($id)],
            )[0] ?? null;
            $credentialEpoch = self::epoch($again['credential_epoch'] ?? null);
            if ($again === null || !$this->holds($again['credentials'] ?? null, new Credentials($user->authIdentifier(), $credentialEpoch, $user->accountPasswordHash()))) {
                return $this->ended($cookie, $id, $realm);
            }
        }

        if ($row['stage'] === SessionStage::SecondFactor->value) {
            return new SessionState($id, $realm, pending: $user);
        }

        // Not on a prefetch: a pointer resting on a link is not someone
        // using the session, and "prefetch requests skip side effects".
        if (!$request->isPrefetch() && (int) $row['last_seen_at'] <= $now - self::TOUCH_EVERY) {
            $this->connection->execute('UPDATE sessions SET last_seen_at = ? WHERE id = ?', [$now, self::hash($id)]);
        }

        $confirmed = \is_numeric($row['confirmed_at'] ?? null) ? (int) $row['confirmed_at'] : null;

        return new SessionState($id, $realm, $user, $this->epochs->of($user->authIdentifier()), confirmedAt: $confirmed, credentialEpoch: $credentialEpoch);
    }

    /**
     * Stamps the session as its owner's, just proved -- by a password typed
     * again, a passkey or an authenticator code -- so for the next five
     * minutes it may change how the account signs in. A session signed in
     * as nobody, or as someone else, is not stamped.
     */
    public function confirm(SessionState $state, AccountUser $user): void
    {
        if ($state->id === '' || $state->user()?->authIdentifier() !== $user->authIdentifier()) {
            return;
        }
        $now = $this->cookie->now();
        $this->connection->execute(
            'UPDATE sessions SET confirmed_at = ? WHERE id = ? AND realm = ? AND user_id = ? AND stage = ?',
            [$now, self::hash($state->id), $state->realm, $user->authIdentifier(), SessionStage::SignedIn->value],
        );
        $state->confirmed($now);
    }

    /** Until when the session's owner last proved it was them counts, or null if it no longer does. */
    public function confirmedUntil(SessionState $state): ?int
    {
        $at = $state->user() === null ? null : $state->confirmedAt();
        $until = $at === null ? null : $at + self::CONFIRMATION_LIFETIME;

        return $until !== null && $until > $this->cookie->now() ? $until : null;
    }

    /**
     * A session with nobody behind it here: signed out. A cookie a sign-in
     * minted had a row, so the server ended it, and it is replaced with a
     * new signed-out one rather than kept (ADR 0075); one minted signed out
     * never had a row, and is kept.
     */
    private function ended(#[\SensitiveParameter] string $cookie, #[\SensitiveParameter] string $id, string $realm): SessionState
    {
        $state = new SessionState($id, $realm);
        if ($this->cookie->signedIn($cookie)) {
            $state->replaceWith($this->cookie->issue(), null);
        }

        return $state;
    }

    /**
     * The realm a request's session belongs to: the host the Kernel
     * resolved (ADR 0149), or the Host header normalized -- lowercase, no
     * port, no trailing dot -- or the address it came to, as on this
     * machine. '' for a request naming neither.
     */
    public static function realmOf(Request $request): string
    {
        $resolved = HostMatch::of($request);
        if ($resolved !== null) {
            return $resolved->host;
        }

        $header = strtolower(trim($request->header('host') ?? ''));
        $host = HostName::normalize($header);
        if ($host !== null) {
            return $host;
        }

        $address = (string) preg_replace('/:\d{1,5}\z/', '', $header);

        return filter_var(trim($address, '[]'), FILTER_VALIDATE_IP) === false ? '' : $address;
    }

    /**
     * Binds the session to $user, under a new id, held to what their sign-in
     * was checked against ($checked, from credentials()), and moves both
     * users' epochs.
     */
    public function signIn(SessionState $state, AccountUser $user, Credentials $checked): void
    {
        $this->rotate($state, $user, SessionStage::SignedIn, $this->sealFor($user, $checked));
    }

    /**
     * Binds the session to $user at the second-factor stage, under a new id,
     * held to what their first factor was checked against: signed in as
     * nobody until passSecondFactor(). $user's epoch stays where it is --
     * nothing of theirs has been let in yet.
     */
    public function beginSecondFactor(SessionState $state, AccountUser $user, Credentials $checked): void
    {
        $this->rotate($state, $user, SessionStage::SecondFactor, $this->sealFor($user, $checked));
    }

    /**
     * Signs in the user whose sign-in waits on its second factor in this
     * session, its code just checked, under a new id -- held to what the
     * first factor was checked against, which the waiting row carries.
     * False when that sign-in has ended since the request began: a sign-out
     * everywhere or a new password deleted the row, or its ten minutes are
     * up.
     */
    public function passSecondFactor(SessionState $state): bool
    {
        $user = $state->pending();

        return $user !== null && $this->rotate($state, $user, SessionStage::SignedIn, promote: true) !== null;
    }

    /** Ends the session, and starts a new signed-out one -- a cookie, no row -- in its place. */
    public function signOut(SessionState $state): void
    {
        $this->rotate($state, null, SessionStage::SignedIn);
    }

    /**
     * Binds the session to $user, brought to this host by a handoff from
     * another of the app's (ADR 0039), under a new id, and returns it.
     *
     * A sign-in like any other -- the id the browser arrived with is never
     * the one signed in -- except for $user's epoch, which stays where it
     * is: they signed in on the host they came from and are the same user
     * here, so nothing any of their tabs holds there has stopped being
     * theirs. Whoever this host's session belonged to before, if anyone
     * else, still moves on. The handoff's redemption is kept with the row,
     * so a replay of its code ends the session (revokeArrival()).
     *
     * Null when $user's auth epoch is no longer $epoch, the one the
     * handoff's code was checked at: they signed in or out elsewhere, or a
     * revocation ended what the code was minted under, since the handoff
     * endpoint checked it. What the session is held to is read before the
     * epoch is asked again, so a revocation committed after the endpoint's
     * check has either moved the epoch by then or ends the session.
     *
     * @param string $realm the host the handoff arrived at (Arrival::$realm): it must be
     *        the one this request came to, the only one the session will be good on
     * @param string $epoch the auth epoch the handoff endpoint checked the code's against (Arrival::$epoch)
     */
    public function arrive(SessionState $state, AccountUser $user, string $realm, string $epoch, string $redemption = ''): ?string
    {
        if ($realm !== $state->realm) {
            throw new \LogicException('A handoff arrives into the session of the host it names; this request came to another.');
        }
        $checked = $this->credentials($user);
        if ($this->epochs->of($user->authIdentifier()) !== $epoch) {
            return null;
        }

        return $this->rotate($state, $user, SessionStage::SignedIn, $this->sealFor($user, $checked), moveEpoch: false, redemption: $redemption);
    }

    /**
     * Ends the session the handoff with this redemption started, on whichever
     * host it is: its code was shown again, so it was copied, and the session
     * may be the copier's (HandoffSessions::revoke()). Durable, as a sign-out
     * is. Nothing for a redemption no session names.
     */
    public function revokeArrival(string $redemption): void
    {
        if ($redemption === '') {
            return;
        }

        $connection = $this->connection;
        $connection->durableTransaction(static function () use ($connection, $redemption): void {
            $connection->execute('DELETE FROM sessions WHERE handoff_redemption = ?', [$redemption]);
        });
    }

    /**
     * Ends every session the session's user has, on every device and host,
     * this one included, and moves their epoch -- and their credential
     * epoch, which their API tokens are held to -- in one durable
     * transaction: every other cookie signs nobody in at its next request,
     * and every token, grant and emailed link minted for them stops counting.
     */
    public function signOutEverywhere(SessionState $state): void
    {
        $this->rotate($state, null, SessionStage::SignedIn, everywhere: true);
    }

    /**
     * Ends every session $user has, inside the caller's transaction -- a
     * password reset's or change's, which moves the epoch in the same one.
     */
    public function endEverywhere(string $user): void
    {
        if (!$this->connection->inTransaction()) {
            throw new \LogicException('Sessions are ended everywhere inside the transaction that decided to end them.');
        }

        $this->connection->execute('DELETE FROM sessions WHERE user_id = ?', [$user]);
    }

    /**
     * Holds $user's sessions that are held to the password hash a rehash
     * replaced -- the same password, hashed as the policy asks now -- to the
     * new one, inside the transaction that stores it, so a rehash at a
     * sign-in signs none of their other devices out. Those held to an epoch
     * moved since were ended, and stay so.
     *
     * Only where the rehash stored its hash in place of $from
     * (AccountUsers::changePasswordFrom(), ADR 0168): a sign-in that found
     * another hash stored first follows nothing, so the sessions stay held
     * to the hash that stays stored.
     */
    public function rehashed(AccountUser $user, #[\SensitiveParameter] string $from, #[\SensitiveParameter] string $to): void
    {
        if (!$this->connection->inTransaction()) {
            throw new \LogicException('Sessions follow a rehash inside the transaction that stores it.');
        }
        $id = $user->authIdentifier();
        $epoch = $this->credentials->of($id);
        $this->connection->execute(
            'UPDATE sessions SET credentials = ? WHERE user_id = ? AND credentials = ?',
            [$this->seal(new Credentials($id, $epoch, $to)), $id, $this->seal(new Credentials($id, $epoch, $from))],
        );
    }

    /**
     * A test's session for $user, with no password asked: the cookie value
     * a browser signed in on $request's host would send.
     */
    public function signedInCookie(AccountUser $user, Request $request): string
    {
        $state = new SessionState('', self::realmOf($request));
        $this->signIn($state, $user, $this->credentials($user));

        return $state->replacement() ?? throw new \LogicException('Signing in made no session.');
    }

    /**
     * @param string|null $seal what the new row is held to (seal()): null for a sign-out, and for a
     *        second factor passed, which carries over the waiting row's ($promote)
     * @param bool $promote whether the new row takes the place of $user's second-factor row in this
     *        session, which must still be there
     * @param bool $moveEpoch whether $user moves to a new epoch; a handoff's arrival does not (arrive())
     * @param string $redemption the handoff code that started the session, hashed (revokeArrival())
     * @return string|null the new session id; null when the second-factor row to promote has gone
     */
    private function rotate(SessionState $state, ?AccountUser $user, SessionStage $stage, ?string $seal = null, bool $promote = false, bool $everywhere = false, bool $moveEpoch = true, string $redemption = ''): ?string
    {
        if ($user !== null && $seal === null && !$promote) {
            throw new \LogicException('A session is made held to what its sign-in was checked against.');
        }
        $now = $this->cookie->now();
        // Minted signed in whenever a row stands behind it, so a cookie
        // whose row is later deleted is known for one the server ended.
        $cookie = $this->cookie->issue(signedIn: $user !== null);
        $id = (string) $this->cookie->id($cookie);
        $expires = $stage === SessionStage::SecondFactor
            ? min(SessionCookie::expiry($cookie), $now + self::SECOND_FACTOR_LIFETIME)
            : SessionCookie::expiry($cookie);

        // Durable: who a session belongs to is what every request is decided
        // by, and a sign-out a power cut took back would sign in again.
        $connection = $this->connection;
        $epochs = $this->epochs;
        $credentials = $this->credentials;
        $made = $connection->durableTransaction(static function () use ($connection, $epochs, $credentials, $state, $user, $stage, $seal, $promote, $everywhere, $moveEpoch, $redemption, $id, $now, $expires): bool {
            // A second factor passed carries over what the first was checked
            // against, from the row that waited for it: gone -- a sign-out
            // everywhere, a new password, its ten minutes up -- and there is
            // nobody to sign in.
            if ($promote) {
                $seal = $connection->select(
                    'SELECT credentials FROM sessions WHERE id = ? AND realm = ? AND user_id = ? AND stage = ? AND expires_at > ?',
                    [self::hash($state->id), $state->realm, $user?->authIdentifier(), SessionStage::SecondFactor->value, $now],
                )[0]['credentials'] ?? null;
                if (!\is_string($seal)) {
                    return false;
                }
            }

            // This host's row alone: an id presented on the wrong host is not
            // that host's to end.
            if ($state->id !== '') {
                $connection->execute('DELETE FROM sessions WHERE id = ? AND realm = ?', [self::hash($state->id), $state->realm]);
            }

            $leaving = $state->user();
            $same = $leaving !== null && $user !== null && $user->authIdentifier() === $leaving->authIdentifier();
            if ($leaving !== null) {
                if ($everywhere) {
                    $connection->execute('DELETE FROM sessions WHERE user_id = ?', [$leaving->authIdentifier()]);
                    $credentials->bump($leaving->authIdentifier());
                }
                // Whatever any tab holds for whoever is leaving is dropped on
                // its next request, not only this tab's -- unless a handoff
                // brings the same user back, who is not leaving at all.
                if ($moveEpoch || !$same) {
                    $epochs->bump($leaving->authIdentifier());
                }
            }
            if ($user === null) {
                return true;
            }
            if ($moveEpoch && $stage === SessionStage::SignedIn && !$same) {
                $epochs->bump($user->authIdentifier());
            }

            // The handoff's column only for a handoff's session, so an app
            // with one host signs in on a table made before the column was.
            $connection->execute(
                $redemption === ''
                    ? 'INSERT INTO sessions (id, user_id, realm, stage, created_at, last_seen_at, expires_at, credentials) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    : 'INSERT INTO sessions (id, user_id, realm, stage, created_at, last_seen_at, expires_at, credentials, handoff_redemption) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [self::hash($id), $user->authIdentifier(), $state->realm, $stage->value, $now, $now, $expires, $seal, ...($redemption === '' ? [] : [$redemption])],
            );

            // In the insert's own transaction, so it costs no second turn at
            // the write lock. A subquery for the bound: SQLite takes LIMIT on
            // a DELETE only when compiled to; MySQL takes it, and refuses a
            // subquery naming the table deleted from.
            $connection->execute(
                $connection instanceof NamesDriver && $connection->driver() === 'mysql'
                    ? 'DELETE FROM sessions WHERE expires_at <= ? LIMIT ' . self::SWEEP_BATCH
                    : 'DELETE FROM sessions WHERE id IN (SELECT id FROM sessions WHERE expires_at <= ? LIMIT ' . self::SWEEP_BATCH . ')',
                [$now],
            );

            return true;
        });
        if (!$made) {
            return null;
        }

        $state->replaceWith($cookie, $stage === SessionStage::SignedIn ? $user : null, $stage === SessionStage::SecondFactor ? $user : null);

        return $id;
    }

    /** $checked, sealed, for a session of $user's: refused when it was read for another account. */
    private function sealFor(AccountUser $user, Credentials $checked): string
    {
        if ($checked->user !== $user->authIdentifier()) {
            throw new \LogicException('A session is held to what its own account\'s sign-in was checked against, never another account\'s.');
        }

        return $this->seal($checked);
    }

    /**
     * $credentials under the app key: what a row keeps, so a copy of the
     * table says nothing of an account's password, and nobody without the
     * key can make a row's credentials match an account's.
     */
    private function seal(Credentials $credentials): string
    {
        return Signer::encode($this->seals->sign(json_encode([$credentials->user, $credentials->epoch, $credentials->passwordHash], JSON_THROW_ON_ERROR)));
    }

    /** Whether a row's sealed credentials are $now's: the account's as they stand. */
    private function holds(mixed $sealed, Credentials $now): bool
    {
        return \is_string($sealed) && hash_equals($this->seal($now), $sealed);
    }

    /** A credential epoch as the database answered it -- an integer, a numeric string, or null for none -- as CredentialEpochs::of() spells it. */
    private static function epoch(mixed $value): string
    {
        return (string) (\is_int($value) || \is_string($value) ? $value : 0);
    }

    private static function hash(#[\SensitiveParameter] string $id): string
    {
        return hash('sha256', $id);
    }
}
