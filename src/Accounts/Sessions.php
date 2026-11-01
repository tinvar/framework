<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Http\HostName;
use Tinvar\Http\Request;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Routing\HostMatch;

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
 * sign-out, a second factor passed -- so an id an attacker planted or saw
 * before the change never carries the privilege after it. And every
 * session belongs to the host it was made on, its realm, and signs nobody
 * in on any other (ADR 0039).
 *
 * Generalized from the example shop's, which keeps its own for its
 * customers and its cross-host handoff.
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

    public function __construct(
        private Connection $connection,
        private SessionCookie $cookie,
        private AccountUsers $users,
        private AuthEpochs $epochs,
    ) {}

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
            $sessions->index(['user_id'], 'sessions_user');
            $sessions->index(['expires_at'], 'sessions_expiry');
        }, $ifNotExists);
    }

    public function cookie(): SessionCookie
    {
        return $this->cookie;
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
            'SELECT user_id, realm, stage, last_seen_at, expires_at FROM sessions WHERE id = ?',
            [self::hash($id)],
        )[0] ?? null;

        // No row, expired, or made on another host: as far as this host
        // knows, nobody's.
        if ($row === null || (int) $row['expires_at'] <= $now || !hash_equals((string) $row['realm'], $realm)) {
            return $this->ended($cookie, $id, $realm);
        }

        $user = \is_scalar($row['user_id']) ? $this->users->find((string) $row['user_id']) : null;
        if ($user === null) {
            return $this->ended($cookie, $id, $realm);
        }

        if ($row['stage'] === SessionStage::SecondFactor->value) {
            return new SessionState($id, $realm, pending: $user);
        }

        // Not on a prefetch: a pointer resting on a link is not someone
        // using the session, and "prefetch requests skip side effects".
        if (!$request->isPrefetch() && (int) $row['last_seen_at'] <= $now - self::TOUCH_EVERY) {
            $this->connection->execute('UPDATE sessions SET last_seen_at = ? WHERE id = ?', [$now, self::hash($id)]);
        }

        return new SessionState($id, $realm, $user, $this->epochs->of($user->authIdentifier()));
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

    /** Binds the session to $user, under a new id, and moves both users' epochs. */
    public function signIn(SessionState $state, AccountUser $user): void
    {
        $this->rotate($state, $user, SessionStage::SignedIn);
    }

    /**
     * Binds the session to $user at the second-factor stage, under a new id:
     * signed in as nobody until completeSecondFactor(). $user's epoch stays
     * where it is -- nothing of theirs has been let in yet.
     */
    public function beginSecondFactor(SessionState $state, AccountUser $user): void
    {
        $this->rotate($state, $user, SessionStage::SecondFactor);
    }

    /** Ends the session, and starts a new signed-out one -- a cookie, no row -- in its place. */
    public function signOut(SessionState $state): void
    {
        $this->rotate($state, null, SessionStage::SignedIn);
    }

    /**
     * Ends every session the session's user has, on every device and host,
     * this one included, and moves their epoch, in one durable transaction:
     * every other cookie signs nobody in at its next request, and every
     * token, grant and emailed link minted for them stops counting.
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
     * A test's session for $user, with no password asked: the cookie value
     * a browser signed in on $request's host would send.
     */
    public function signedInCookie(AccountUser $user, Request $request): string
    {
        $state = new SessionState('', self::realmOf($request));
        $this->signIn($state, $user);

        return $state->replacement() ?? throw new \LogicException('Signing in made no session.');
    }

    private function rotate(SessionState $state, ?AccountUser $user, SessionStage $stage, bool $everywhere = false): void
    {
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
        $connection->durableTransaction(static function () use ($connection, $epochs, $state, $user, $stage, $everywhere, $id, $now, $expires): void {
            // This host's row alone: an id presented on the wrong host is not
            // that host's to end.
            if ($state->id !== '') {
                $connection->execute('DELETE FROM sessions WHERE id = ? AND realm = ?', [self::hash($state->id), $state->realm]);
            }

            $leaving = $state->user();
            if ($leaving !== null) {
                if ($everywhere) {
                    $connection->execute('DELETE FROM sessions WHERE user_id = ?', [$leaving->authIdentifier()]);
                }
                // Whatever any tab holds for whoever is leaving is dropped on
                // its next request, not only this tab's.
                $epochs->bump($leaving->authIdentifier());
            }
            if ($user === null) {
                return;
            }
            if ($stage === SessionStage::SignedIn && $user->authIdentifier() !== $leaving?->authIdentifier()) {
                $epochs->bump($user->authIdentifier());
            }

            $connection->execute(
                'INSERT INTO sessions (id, user_id, realm, stage, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [self::hash($id), $user->authIdentifier(), $state->realm, $stage->value, $now, $now, $expires],
            );

            // In the insert's own transaction, so it costs no second turn at
            // the write lock. A subquery for the bound: SQLite takes LIMIT on
            // a DELETE only when compiled to.
            $connection->execute(
                'DELETE FROM sessions WHERE id IN (SELECT id FROM sessions WHERE expires_at <= ? LIMIT ' . self::SWEEP_BATCH . ')',
                [$now],
            );
        });

        $state->replaceWith($cookie, $stage === SessionStage::SignedIn ? $user : null, $stage === SessionStage::SecondFactor ? $user : null);
    }

    private static function hash(#[\SensitiveParameter] string $id): string
    {
        return hash('sha256', $id);
    }
}
