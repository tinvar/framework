<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Carbon\CarbonImmutable;
use Tinvar\Accounts\Impersonation\Impersonation;
use Tinvar\Accounts\Impersonation\ImpersonationRefusal;
use Tinvar\Accounts\Impersonation\ImpersonationRefused;
use Tinvar\Accounts\Impersonation\Impersonations;
use Tinvar\Http\HostName;
use Tinvar\Http\Request;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TenantBounds;
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
 * On a host that serves many tenants, a session runs as the one its user
 * chose (switchTenant()), and StartSession runs each request as it. A
 * switch rotates the id too, as every change of what a session runs as
 * does, but is no new sign-in: it moves no epoch and carries over what the
 * session is held to, its handoff and its expiry. Every other rotation
 * makes a session with no tenant chosen (ADR 0181).
 *
 * And every row records what its sign-in proved (SessionFactors, ADR 0184)
 * -- a password, a passkey, a password and a code -- sealed under the app
 * key to the row's id, realm and user, so a row given another's factors, or
 * factors it never proved, reads as having none. A sign-in records its own;
 * a switch of tenant and a change the session asks for carry the session's;
 * a handoff's arrival takes those of the session it came from; and a code or
 * a passkey proved on a session adds to them under a new id (stepUp()).
 *
 * And every session is held to what its sign-in was checked against
 * (Credentials): the account's credential epoch, which every revocation
 * moves -- a sign-out everywhere, a new password or address, two-factor
 * turned off, a passkey removed -- and its password hash. A revocation
 * deletes the rows it sees; a sign-in checked before it whose row came
 * after is one it could not see, and signs nobody in from its next request
 * on, as if deleted (ADR 0165).
 *
 * And a session may be an impersonation (ADR 0194): staff acting as one of
 * the app's users, their own session parked under a key no cookie names
 * while they act (impersonate()); it ends with them. Such a row names its
 * impersonation, and is read with it on every request: once it has run
 * out, its user's auth epoch has moved, or the parked session has gone,
 * it signs nobody in, and is ended with what it parked.
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

    /** What a row's factors are sealed under, with the app key. */
    private const string FACTORS_PURPOSE = 'session-factors';

    /** Where open() reads a session's row with its account's credential and auth epochs, by the row's hashed id. */
    private const string WITH_EPOCHS = 'FROM sessions s LEFT JOIN ' . CredentialEpochs::TABLE . ' c ON c.user_id = s.user_id '
        . 'LEFT JOIN ' . AuthEpochs::TABLE . ' a ON a.user_id = s.user_id WHERE s.id = ?';

    private Signer $seals;

    private Signer $factorSeals;

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
        $this->factorSeals = new Signer($key, self::FACTORS_PURPOSE);
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
            // The tenant the session runs as (switchTenant()), as long as the
            // ORM's tenant columns are; null until its user chooses one.
            $sessions->string('tenant', 255)->nullable();
            // What its sign-in proved (SessionFactors), sealed to the row
            // (sealFactors()); null on a row made before factors were kept,
            // which reads as none.
            $sessions->string('factors', 128)->nullable();
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

    /**
     * Adds `tenant` to a sessions table define() made before it had one,
     * for an app that serves many tenants on one host; a table that has it
     * is left as it is. Every session there runs as no tenant until its user
     * switches, and one never switched needs no such column: open() reads
     * the row whole.
     */
    public static function addTenant(Schema $schema): void
    {
        if ($schema->hasColumn('sessions', 'tenant')) {
            return;
        }
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->string('tenant', 255)->nullable();
        });
    }

    /**
     * Adds `factors` to a sessions table define() made before it had one; a
     * table that has it is left as it is. Every session there was signed in
     * before its factors were kept, and reads as having none -- signed in
     * still, but no page that asks for two factors opens to it until it
     * proves them again (ADR 0184). Every sign-in writes the column, so an
     * app on the kit runs this before it serves.
     */
    public static function addFactors(Schema $schema): void
    {
        if ($schema->hasColumn('sessions', 'factors')) {
            return;
        }
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->string('factors', 128)->nullable();
        });
    }

    /**
     * Adds `impersonation` to a sessions table, for an app that lets its
     * staff impersonate its users (ADR 0194): the impersonation a session is,
     * its row's id in the impersonations table; null for every other. A
     * table that has it is left as it is. define() makes none, so an app's
     * accounts migration makes what it always made, and one that never
     * impersonates needs none: open() reads the row whole.
     */
    public static function addImpersonation(Schema $schema): void
    {
        if ($schema->hasColumn('sessions', 'impersonation')) {
            return;
        }
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->string('impersonation', 64)->nullable();
        });
    }

    /** Takes `impersonation` off the sessions table again, for a migration's down(); a table without it is left as it is. */
    public static function dropImpersonation(Schema $schema): void
    {
        if (!$schema->hasColumn('sessions', 'impersonation')) {
            return;
        }
        $schema->table('sessions', static function (Table $sessions): void {
            $sessions->dropColumn('impersonation');
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
        // The row, and both of the account's epochs, in one statement: one
        // snapshot. The request runs on the auth epoch read here, so a
        // revocation -- which moves both in one transaction -- either came
        // before it, and the seal below fails, or after it, and moves on
        // from the epoch the request binds what it mints to (ADR 0171).
        // The row whole, so a table made before it had a tenant column --
        // in an app that never switches one -- is read as it was (ADR 0181).
        $row = $this->connection->select(
            'SELECT s.*, c.epoch AS credential_epoch, a.epoch AS auth_epoch ' . self::WITH_EPOCHS,
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
            // Both epochs again too, from the same snapshot as the seal.
            $again = $this->connection->select(
                'SELECT s.credentials, c.epoch AS credential_epoch, a.epoch AS auth_epoch ' . self::WITH_EPOCHS,
                [self::hash($id)],
            )[0] ?? null;
            $credentialEpoch = self::epoch($again['credential_epoch'] ?? null);
            $row['auth_epoch'] = $again['auth_epoch'] ?? null;
            if ($again === null || !$this->holds($again['credentials'] ?? null, new Credentials($user->authIdentifier(), $credentialEpoch, $user->accountPasswordHash()))) {
                return $this->ended($cookie, $id, $realm);
            }
        }

        if ($row['stage'] === SessionStage::SecondFactor->value) {
            return new SessionState($id, $realm, pending: $user);
        }

        // An impersonation, held to what it was started under on every
        // request: ended -- run out, its user's epoch moved, the parked
        // session gone -- it is ended here, with what it parked.
        $impersonation = null;
        $named = $row['impersonation'] ?? null;
        if (\is_string($named) && $named !== '') {
            $impersonation = $this->impersonationOf($named, $row, $realm, self::epoch($row['auth_epoch'] ?? null), $now);
            if ($impersonation === null) {
                $this->endImpersonation($id, $named, (string) $row['user_id']);

                return $this->ended($cookie, $id, $realm);
            }
        }

        // Not on a prefetch: a pointer resting on a link is not someone
        // using the session, and "prefetch requests skip side effects".
        if (!$request->isPrefetch() && (int) $row['last_seen_at'] <= $now - self::TOUCH_EVERY) {
            $this->connection->execute('UPDATE sessions SET last_seen_at = ? WHERE id = ?', [$now, self::hash($id)]);
        }

        $confirmed = \is_numeric($row['confirmed_at'] ?? null) ? (int) $row['confirmed_at'] : null;
        $tenant = $row['tenant'] ?? null;

        return new SessionState(
            $id,
            $realm,
            $user,
            self::epoch($row['auth_epoch'] ?? null),
            confirmedAt: $confirmed,
            credentialEpoch: $credentialEpoch,
            tenant: \is_string($tenant) && $tenant !== '' ? $tenant : null,
            factors: $this->factorsOf($row, $id, $realm, $user->authIdentifier()),
            impersonation: $impersonation,
        );
    }

    /**
     * The impersonation a signed-in row names, while it stands: its row
     * there, for this row's user on this host, arrived, unexpired, at the
     * auth epoch it began at, and the impersonator's parked session still
     * there and theirs. Null otherwise.
     *
     * @param array<string, mixed> $session
     */
    private function impersonationOf(string $id, array $session, string $realm, string $epoch, int $now): ?Impersonation
    {
        $row = $this->connection->select(
            'SELECT i.*, p.user_id AS parked_user, p.realm AS parked_realm, p.expires_at AS parked_expires FROM ' . Impersonations::TABLE
            . ' i LEFT JOIN sessions p ON p.id = i.parked WHERE i.id = ?',
            [$id],
        )[0] ?? null;
        if ($row === null || !\is_string($row['parked'] ?? null) || !is_numeric($row['arrived_at'] ?? null)
            || (string) $row['user_id'] !== (string) $session['user_id'] || (string) $row['realm'] !== $realm
            || (string) ($row['tenant'] ?? '') !== (string) ($session['tenant'] ?? '')
            || (int) $row['expires_at'] <= $now || self::epoch($row['epoch'] ?? null) !== $epoch
            || (string) ($row['parked_user'] ?? '') !== (string) $row['impersonator_id']
            || (string) ($row['parked_realm'] ?? '') !== $realm || (int) ($row['parked_expires'] ?? 0) <= $now) {
            return null;
        }

        return new Impersonation($id, (string) $row['impersonator_id'], CarbonImmutable::createFromTimestamp((int) $row['expires_at']), (string) $row['reason']);
    }

    /**
     * Ends an impersonation open() found over, in one durable transaction:
     * the session, its row and the session it parked, and -- once, by
     * whichever request deletes its row -- its user's auth epoch moved, so
     * nothing minted while they were acted as counts after.
     */
    private function endImpersonation(#[\SensitiveParameter] string $id, string $impersonation, string $user): void
    {
        $connection = $this->connection;
        $epochs = $this->epochs;
        $connection->durableTransaction(static function () use ($connection, $epochs, $id, $impersonation, $user): void {
            $connection->execute('DELETE FROM sessions WHERE id = ? AND impersonation = ?', [self::hash($id), $impersonation]);
            self::forgetImpersonation($connection, $impersonation, $user, $epochs);
        });
    }

    /**
     * Deletes an impersonation's row and the session it parked, inside the
     * caller's transaction, and moves its user's epoch -- where this call
     * deleted the row, so two requests ending it move it once.
     */
    private static function forgetImpersonation(Connection $connection, string $impersonation, string $user, ?AuthEpochs $epochs): void
    {
        $parked = $connection->select('SELECT parked FROM ' . Impersonations::TABLE . ' WHERE id = ?', [$impersonation])[0]['parked'] ?? null;
        if (\is_string($parked) && $parked !== '') {
            $connection->execute('DELETE FROM sessions WHERE id = ?', [$parked]);
        }
        if ($connection->execute('DELETE FROM ' . Impersonations::TABLE . ' WHERE id = ?', [$impersonation]) === 1) {
            $epochs?->bump($user);
        }
    }

    /**
     * Turns the session, signed in as $impersonator, into $target's, as the
     * impersonation $impersonation, inside the caller's transaction
     * (Impersonations, ADR 0194), and returns what the caller needs:
     *
     * - the session as it was is parked under a new key, `parked`: a random
     *   one, which no cookie's id hashes to, so nothing reaches it. It is
     *   what the impersonation is held to -- a revocation of the
     *   impersonator's deletes it, and the impersonation ends -- and is
     *   deleted with it, never made a session again (ADR 0194);
     * - a new session for $target, under a new id, `cookie`: held to their
     *   credentials as they stand now, so a revocation of theirs ends it; with
     *   what the impersonator's own sign-in proved, `factors`, and nothing of
     *   $target's; running as $tenant, the one it was started in, and never
     *   another (switchTenant() refuses it); and ending, at the latest, when
     *   the parked one would have, `expires`.
     *
     * No epoch moves here: the caller moves $target's, in the same
     * transaction. Null, with nothing made, where the session has ended
     * since the request opened it, or is an impersonation itself.
     *
     * @return array{cookie: string, parked: string, expires: int, factors: SessionFactors}|null
     */
    public function impersonate(SessionState $state, AccountUser $impersonator, AccountUser $target, string $impersonation, ?string $tenant = null): ?array
    {
        if (!$this->connection->inTransaction()) {
            throw new \LogicException('A session is turned into an impersonation inside the transaction that records it.');
        }
        if ($state->id === '' || $state->user()?->authIdentifier() !== $impersonator->authIdentifier()) {
            return null;
        }
        $now = $this->cookie->now();
        $row = $this->connection->select(
            'SELECT * FROM sessions WHERE id = ? AND realm = ? AND user_id = ? AND stage = ? AND expires_at > ?',
            [self::hash($state->id), $state->realm, $impersonator->authIdentifier(), SessionStage::SignedIn->value, $now],
        )[0] ?? null;
        $named = $row['impersonation'] ?? null;
        if ($row === null || !\is_string($row['credentials'] ?? null) || (\is_string($named) && $named !== '')) {
            return null;
        }
        if ($this->connection->execute('DELETE FROM sessions WHERE id = ? AND realm = ?', [self::hash($state->id), $state->realm]) !== 1) {
            return null;
        }

        $factors = $this->factorsOf($row, $state->id, $state->realm, $impersonator->authIdentifier());
        $expires = (int) $row['expires_at'];
        $parked = bin2hex(random_bytes(32));
        // The impersonator's own tenant goes to their parked session alone;
        // the target's runs in the tenant the impersonation was started in
        // ($tenant), never the impersonator's.
        $own = $row['tenant'] ?? null;
        $redemption = $row['handoff_redemption'] ?? null;
        self::insert($this->connection, [
            'id' => $parked,
            'user_id' => $impersonator->authIdentifier(),
            'realm' => $state->realm,
            'stage' => SessionStage::SignedIn->value,
            'created_at' => (int) $row['created_at'],
            'last_seen_at' => $now,
            'expires_at' => $expires,
            'credentials' => $row['credentials'],
            'factors' => self::sealFactorsWith($this->factorSeals, $factors, $parked, $state->realm, $impersonator->authIdentifier(), hashed: true),
            'tenant' => \is_string($own) && $own !== '' ? $own : null,
            'handoff_redemption' => \is_string($redemption) && $redemption !== '' ? $redemption : null,
        ]);

        $cookie = $this->cookie->issue(signedIn: true, until: $expires);
        $id = (string) $this->cookie->id($cookie);
        self::insert($this->connection, [
            'id' => self::hash($id),
            'user_id' => $target->authIdentifier(),
            'realm' => $state->realm,
            'stage' => SessionStage::SignedIn->value,
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $expires,
            'credentials' => $this->sealFor($target, $this->credentials($target)),
            'factors' => $this->sealFactors($factors, $id, $state->realm, $target->authIdentifier()),
            'tenant' => $tenant === null || $tenant === '' ? null : $tenant,
            'impersonation' => $impersonation,
        ]);

        return ['cookie' => $cookie, 'parked' => $parked, 'expires' => $expires, 'factors' => $factors];
    }

    /**
     * Ends the impersonation the session is, inside the caller's transaction
     * (Impersonations::stop()): deletes its row here, and false where another
     * request ended it first.
     */
    public function endImpersonated(SessionState $state, string $impersonation): bool
    {
        if (!$this->connection->inTransaction()) {
            throw new \LogicException('An impersonation is ended inside the transaction that records it.');
        }

        return $state->id !== ''
            && $this->connection->execute('DELETE FROM sessions WHERE id = ? AND realm = ? AND impersonation = ?', [self::hash($state->id), $state->realm, $impersonation]) === 1;
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
     * users' epochs. $factors is what the sign-in proved; none unless given,
     * and a change the session asks for, which signs it in again, hands in
     * the session's own (SessionState::factors()).
     */
    public function signIn(SessionState $state, AccountUser $user, Credentials $checked, ?SessionFactors $factors = null): void
    {
        $this->rotate($state, $user, SessionStage::SignedIn, $this->sealFor($user, $checked), factors: $factors);
    }

    /**
     * Binds the session to $user at the second-factor stage, under a new id,
     * held to what their first factor was checked against: signed in as
     * nobody until passSecondFactor(). $user's epoch stays where it is --
     * nothing of theirs has been let in yet.
     */
    public function beginSecondFactor(SessionState $state, AccountUser $user, Credentials $checked, ?SessionFactors $first = null): void
    {
        $this->rotate($state, $user, SessionStage::SecondFactor, $this->sealFor($user, $checked), factors: $first);
    }

    /**
     * Signs in the user whose sign-in waits on its second factor in this
     * session, its code just checked, under a new id -- held to what the
     * first factor was checked against, which the waiting row carries, and
     * with what it proved, and $second.
     * False when that sign-in has ended since the request began: a sign-out
     * everywhere or a new password deleted the row, or its ten minutes are
     * up.
     */
    public function passSecondFactor(SessionState $state, SignInFactor $second = SignInFactor::AuthenticatorCode): bool
    {
        $user = $state->pending();
        if (!$second->isSecond()) {
            throw new \InvalidArgumentException('A second factor is a code: ' . $second->value . ' is not one.');
        }

        return $user !== null && $this->rotate($state, $user, SessionStage::SignedIn, promote: true, factors: SessionFactors::of($second)) !== null;
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
     * so a replay of its code ends the session (revokeArrival()). It was
     * signed in with what the session the handoff came from proved
     * ($factors, which the handoff's code carries under its MAC), and no
     * more.
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
    public function arrive(SessionState $state, AccountUser $user, string $realm, string $epoch, string $redemption = '', ?SessionFactors $factors = null): ?string
    {
        if ($realm !== $state->realm) {
            throw new \LogicException('A handoff arrives into the session of the host it names; this request came to another.');
        }
        $checked = $this->credentials($user);
        if ($this->epochs->of($user->authIdentifier()) !== $epoch) {
            return null;
        }

        return $this->rotate($state, $user, SessionStage::SignedIn, $this->sealFor($user, $checked), moveEpoch: false, redemption: $redemption, factors: $factors);
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
     * Runs the session, signed in as $user, as $tenant from its next request
     * on, under a new id, and returns it: a switch between the tenants of an
     * app that serves many on one host (TenantSessions, ADR 0181).
     *
     * A new id like every other change of what a session runs as: every
     * token the old tenant's pages hold is bound to the old id, and ends
     * with it, so a tab left open there posts nothing into this one. But no
     * new sign-in: the user and their epochs stay where they are -- a switch
     * in one browser purges nothing another holds -- and the new row is
     * held to what the old one was, read from it, so a revocation since, or
     * a replay of the handoff that started it, still ends it. It ends when
     * the old one would have, and its owner's proof that it was them is not
     * carried over.
     *
     * Whether $user may run as $tenant is the caller's to have decided
     * (TenantSwitcher), and each panel's membership check decides again on
     * every request.
     *
     * Null when the session has ended since the request opened it -- a
     * sign-out everywhere, its expiry, another switch of it -- and nothing
     * was made.
     *
     * @throws \LogicException where the session is not signed in as $user
     * @throws \InvalidArgumentException for a tenant no tenant can be named
     */
    public function switchTenant(SessionState $state, AccountUser $user, string $tenant): ?string
    {
        if ($state->id === '' || $state->user()?->authIdentifier() !== $user->authIdentifier()) {
            throw new \LogicException('A session is switched to another tenant only by the user it is signed in as.');
        }
        // An impersonation runs in the tenant it was started in, and the
        // policy was asked about that one alone (ADR 0194).
        if ($state->impersonation() !== null) {
            throw new ImpersonationRefused(ImpersonationRefusal::WhileImpersonating);
        }
        if (!TenantBounds::isName($tenant) || \strlen($tenant) > 255) {
            throw new \InvalidArgumentException('A session runs as a tenant with a name: printable, and as long as a tenant column holds.');
        }

        return $this->carry($state, $user, tenant: $tenant);
    }

    /**
     * Adds $factor, just proved on this session -- a code from the account's
     * authenticator app, or one of its passkeys -- to what the session's
     * sign-in proved, under a new id, and stamps it confirmed: the "confirm
     * it's you" step, which a page that asks for two factors sends a session
     * of one to (RequireMultiFactor, ADR 0184).
     *
     * A new id, as for every change of what a session may do: a copy of the
     * cookie taken before -- one a thief holds beside its owner -- is not
     * stepped up with it, and ends. Otherwise a rotation that is no sign-in,
     * as a switch is: the same user, epochs, credentials, handoff, tenant and
     * expiry, read from the row. A factor the session has already proved
     * changes nothing but the confirmation, under the same id.
     *
     * False, with nothing made, where the session is not signed in as $user,
     * or has ended since the request opened it.
     */
    public function stepUp(SessionState $state, AccountUser $user, SignInFactor $factor): bool
    {
        if ($state->id === '' || $state->user()?->authIdentifier() !== $user->authIdentifier()) {
            return false;
        }
        if ($state->factors()->has($factor)) {
            $this->confirm($state, $user);

            return $state->confirmedAt() !== null;
        }

        return $this->carry($state, $user, add: $factor) !== null;
    }

    /**
     * The session, signed in as $user, under a new id in one durable
     * transaction, with what its row holds -- the user, realm, sealed
     * credentials, handoff redemption, tenant, factors and expiry -- carried
     * over, and the id it had ended: a switch of tenant to $tenant, or a step
     * up adding $add to its factors and stamping it confirmed. No epoch
     * moves. The new id; null where the row has gone since the request
     * opened it, and nothing was made.
     */
    private function carry(SessionState $state, AccountUser $user, ?string $tenant = null, ?SignInFactor $add = null): ?string
    {
        $now = $this->cookie->now();
        $cookies = $this->cookie;
        $connection = $this->connection;
        $factorsOf = $this->factorsOf(...);
        $sealFactors = $this->sealFactors(...);
        // Durable, as every rotation is: what a session runs as decides
        // every request, and an old id a power cut brought back would too.
        $made = $connection->durableTransaction(static function () use ($connection, $cookies, $factorsOf, $sealFactors, $state, $user, $tenant, $add, $now): ?array {
            // The row whole, as open() reads it: a table made before the
            // handoff's column, or the tenant's, has none to carry.
            $row = $connection->select(
                'SELECT * FROM sessions WHERE id = ? AND realm = ? AND user_id = ? AND stage = ? AND expires_at > ?',
                [self::hash($state->id), $state->realm, $user->authIdentifier(), SessionStage::SignedIn->value, $now],
            )[0] ?? null;
            if ($row === null || !\is_string($row['credentials'] ?? null)) {
                return null;
            }

            $expires = (int) $row['expires_at'];
            $cookie = $cookies->issue(signedIn: true, until: $expires);
            $id = (string) $cookies->id($cookie);
            $redemption = $row['handoff_redemption'] ?? null;
            $impersonation = $row['impersonation'] ?? null;
            $kept = $row['tenant'] ?? null;
            $tenant ??= \is_string($kept) && $kept !== '' ? $kept : null;
            // What the row proved, as its seal says -- none where it says
            // nothing, or something else -- and what was just proved.
            $factors = $factorsOf($row, $state->id, $state->realm, $user->authIdentifier());
            if ($add !== null) {
                $factors = $factors->with($add);
            }

            // The row read is the one taken, or another rotation of this
            // session -- a double click, a copy of the cookie -- took it
            // first, and that one's session is the only one made from it.
            if ($connection->execute('DELETE FROM sessions WHERE id = ? AND realm = ?', [self::hash($state->id), $state->realm]) !== 1) {
                return null;
            }
            self::insert($connection, [
                'id' => self::hash($id),
                'user_id' => $user->authIdentifier(),
                'realm' => $state->realm,
                'stage' => SessionStage::SignedIn->value,
                'created_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $expires,
                'credentials' => $row['credentials'],
                'factors' => $sealFactors($factors, $id, $state->realm, $user->authIdentifier()),
                'tenant' => $tenant,
                'handoff_redemption' => \is_string($redemption) && $redemption !== '' ? $redemption : null,
                // An impersonation stays one, whatever it switches to: its
                // impersonator, time box and audit trail go with it.
                'impersonation' => \is_string($impersonation) && $impersonation !== '' ? $impersonation : null,
                // A switch carries no confirmation; a step up is one.
                'confirmed_at' => $add === null ? null : $now,
            ]);

            return [$cookie, $id, $tenant, $factors];
        });
        if ($made === null) {
            return null;
        }
        [$cookie, $id, $tenant, $factors] = $made;

        $state->replaceWith($cookie, $user, tenant: $tenant, factors: $factors, impersonation: $state->impersonation());
        if ($add !== null) {
            $state->confirmed($now);
        }

        return $id;
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
     * a browser signed in on $request's host would send, signed in with
     * $factors -- none unless given, so a page that asks for two factors
     * sends it on to prove them, as it would a session of one.
     */
    public function signedInCookie(AccountUser $user, Request $request, ?SessionFactors $factors = null): string
    {
        $state = new SessionState('', self::realmOf($request));
        $this->signIn($state, $user, $this->credentials($user), $factors);

        return $state->replacement() ?? throw new \LogicException('Signing in made no session.');
    }

    /**
     * @param string|null $seal what the new row is held to (seal()): null for a sign-out, and for a
     *        second factor passed, which carries over the waiting row's ($promote)
     * @param bool $promote whether the new row takes the place of $user's second-factor row in this
     *        session, which must still be there
     * @param bool $moveEpoch whether $user moves to a new epoch; a handoff's arrival does not (arrive())
     * @param string $redemption the handoff code that started the session, hashed (revokeArrival())
     * @param SessionFactors|null $factors what the sign-in proved -- for a second factor passed, the
     *        code, added to what the waiting row's first factor proved -- none unless given
     * @return string|null the new session id; null when the second-factor row to promote has gone
     */
    private function rotate(SessionState $state, ?AccountUser $user, SessionStage $stage, ?string $seal = null, bool $promote = false, bool $everywhere = false, bool $moveEpoch = true, string $redemption = '', ?SessionFactors $factors = null): ?string
    {
        $factors ??= SessionFactors::none();
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
        $factorsOf = $this->factorsOf(...);
        $factorSigner = $this->factorSeals;
        $made = $connection->durableTransaction(static function () use ($connection, $epochs, $credentials, $factorsOf, $factorSigner, $state, $user, $stage, $seal, $promote, $everywhere, $moveEpoch, $redemption, $id, $now, $expires, &$factors): bool {
            // A second factor passed carries over what the first was checked
            // against, from the row that waited for it: gone -- a sign-out
            // everywhere, a new password, its ten minutes up -- and there is
            // nobody to sign in. And what the first proved, as its seal says.
            if ($promote) {
                $waiting = $connection->select(
                    'SELECT * FROM sessions WHERE id = ? AND realm = ? AND user_id = ? AND stage = ? AND expires_at > ?',
                    [self::hash($state->id), $state->realm, $user?->authIdentifier(), SessionStage::SecondFactor->value, $now],
                )[0] ?? null;
                $seal = $waiting['credentials'] ?? null;
                if ($waiting === null || $user === null || !\is_string($seal)) {
                    return false;
                }
                $factors = $factorsOf($waiting, $state->id, $state->realm, $user->authIdentifier())->with(...$factors->all());
            }

            // This host's row alone: an id presented on the wrong host is not
            // that host's to end.
            if ($state->id !== '') {
                $connection->execute('DELETE FROM sessions WHERE id = ? AND realm = ?', [self::hash($state->id), $state->realm]);
            }
            // An impersonation left by a sign-out or a sign-in as someone
            // else ends, and the session it parked with it (ADR 0194); the
            // epoch of whoever is leaving moves below, as for anyone.
            $left = $state->impersonation();
            $acted = $state->user();
            if ($left !== null && $acted !== null) {
                self::forgetImpersonation($connection, $left->id, $acted->authIdentifier(), null);
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
            self::insert($connection, [
                'id' => self::hash($id),
                'user_id' => $user->authIdentifier(),
                'realm' => $state->realm,
                'stage' => $stage->value,
                'created_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => $expires,
                'credentials' => $seal,
                'factors' => self::sealFactorsWith($factorSigner, $factors, $id, $state->realm, $user->authIdentifier()),
                'handoff_redemption' => $redemption === '' ? null : $redemption,
            ]);

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

        $state->replaceWith($cookie, $stage === SessionStage::SignedIn ? $user : null, $stage === SessionStage::SecondFactor ? $user : null, factors: $factors);

        return $id;
    }

    /**
     * What a row's sign-in proved, as its seal says: the factors sealed to
     * this row's id, realm and user (sealFactors()), and none for a row with
     * no seal, or one made for another row or rewritten -- fewer factors are
     * all a seal it cannot vouch for can mean.
     *
     * @param array<string, mixed> $row
     */
    private function factorsOf(array $row, #[\SensitiveParameter] string $id, string $realm, string $user): SessionFactors
    {
        $sealed = $row['factors'] ?? null;

        return $this->factorsOfKey(\is_string($sealed) ? $sealed : null, self::hash($id), $realm, $user);
    }

    /** factorsOf(), for the row keyed $key: a session's hashed id, or a parked one's key. */
    private function factorsOfKey(?string $sealed, string $key, string $realm, string $user): SessionFactors
    {
        $dot = \is_string($sealed) ? strrpos($sealed, '.') : false;
        if (!\is_string($sealed) || $dot === false) {
            return SessionFactors::none();
        }
        $encoded = substr($sealed, 0, $dot);
        $factors = SessionFactors::decode($encoded);

        // Compared as written, so an encoding the decode reads differently
        // -- a name it passes over, an order it puts right -- is no match.
        return $factors->encode() === $encoded && hash_equals(self::sealFactorsWith($this->factorSeals, $factors, $key, $realm, $user, hashed: true), $sealed)
            ? $factors
            : SessionFactors::none();
    }

    /** $factors, as a row keeps them: readable, and sealed to the row's id, realm and user under the app key. */
    private function sealFactors(SessionFactors $factors, #[\SensitiveParameter] string $id, string $realm, string $user): string
    {
        return self::sealFactorsWith($this->factorSeals, $factors, $id, $realm, $user);
    }

    /** @param bool $hashed whether $id is the row's key already: a hashed id, or a parked session's key */
    private static function sealFactorsWith(Signer $signer, SessionFactors $factors, #[\SensitiveParameter] string $id, string $realm, string $user, bool $hashed = false): string
    {
        $encoded = $factors->encode();

        return $encoded . '.' . Signer::encode($signer->sign(json_encode([$hashed ? $id : self::hash($id), $realm, $user, $encoded], JSON_THROW_ON_ERROR)));
    }

    /**
     * A row, with the columns given a value: one left null is left out, so
     * a table made before an optional column -- a handoff's, a tenant's --
     * takes a row that has nothing for it.
     *
     * @param array<string, string|int|null> $columns
     */
    private static function insert(Connection $connection, array $columns): void
    {
        $columns = array_filter($columns, static fn (string|int|null $value): bool => $value !== null);
        $connection->execute(
            'INSERT INTO sessions (' . implode(', ', array_keys($columns)) . ') VALUES (' . implode(', ', array_fill(0, \count($columns), '?')) . ')',
            array_values($columns),
        );
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

    /** An epoch as the database answered it -- an integer, a numeric string, or null for none -- as CredentialEpochs::of() and AuthEpochs::of() spell it. */
    private static function epoch(mixed $value): string
    {
        return (string) (\is_int($value) || \is_string($value) ? $value : 0);
    }

    private static function hash(#[\SensitiveParameter] string $id): string
    {
        return hash('sha256', $id);
    }
}
