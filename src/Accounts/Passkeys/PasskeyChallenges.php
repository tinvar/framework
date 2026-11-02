<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * The challenge a passkey ceremony signs, bound to the session that asked
 * for it, to what it is for, and -- for adding a passkey or confirming a
 * session -- to the account; good once, for five minutes.
 *
 * One per session and purpose: asking again replaces it, and using it
 * deletes it in the same statement that reads it (DELETE ... RETURNING),
 * so a signed response replayed -- from a log, a second tab, a proxy --
 * finds nothing to match. Kept by a hash of the session id, so the table
 * is no list of cookies.
 */
final readonly class PasskeyChallenges
{
    /** How long a ceremony has, in seconds: what the browser is told as its timeout, and a little over. */
    public const int LIFETIME = 300;

    public const int BYTES = 32;

    public const string REGISTER = 'register';
    public const string AUTHENTICATE = 'authenticate';
    // Confirming a signed-in session is its owner's, with one of the account's passkeys.
    public const string CONFIRM = 'confirm';

    /** Expired rows forgotten per challenge made, at most. */
    public const int SWEEP_BATCH = 100;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private Connection $connection, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public static function define(Schema $schema, bool $ifNotExists = false): void
    {
        $schema->create('passkey_challenges', static function (Table $challenges): void {
            $challenges->string('session_hash', 64);
            $challenges->string('purpose', 16);
            $challenges->string('challenge', 64);
            // The account adding a passkey or confirming; '' for a sign-in, which names none.
            $challenges->string('user_id', 191);
            $challenges->bigInteger('expires_at');
            $challenges->primary(['session_hash', 'purpose']);
            $challenges->index(['expires_at'], 'passkey_challenges_expiry');
        }, $ifNotExists);
    }

    /** A new challenge's raw bytes, for $session and $purpose, replacing any before it. */
    public function issue(#[\SensitiveParameter] string $session, string $purpose, string $user = ''): string
    {
        self::assertBound($session, $purpose);
        $challenge = random_bytes(self::BYTES);
        $now = ($this->clock)();
        $connection = $this->connection;
        $connection->transaction(static function () use ($connection, $session, $purpose, $user, $challenge, $now): void {
            $connection->execute('DELETE FROM passkey_challenges WHERE session_hash = ? AND purpose = ?', [hash('sha256', $session), $purpose]);
            $connection->execute(
                'INSERT INTO passkey_challenges (session_hash, purpose, challenge, user_id, expires_at) VALUES (?, ?, ?, ?, ?)',
                [hash('sha256', $session), $purpose, Base64Url::encode($challenge), $user, $now + self::LIFETIME],
            );
            $connection->execute(
                'DELETE FROM passkey_challenges WHERE session_hash IN (SELECT session_hash FROM passkey_challenges WHERE expires_at <= ? LIMIT ' . self::SWEEP_BATCH . ')',
                [$now],
            );
        });

        return $challenge;
    }

    /**
     * The challenge $session was issued for $purpose -- and, for adding a
     * passkey or confirming, for $user -- used up by this call; null when there is none,
     * it has expired, or it was another account's.
     */
    public function consume(#[\SensitiveParameter] string $session, string $purpose, string $user = ''): ?string
    {
        self::assertBound($session, $purpose);
        $row = $this->connection->select(
            'DELETE FROM passkey_challenges WHERE session_hash = ? AND purpose = ? RETURNING challenge, user_id, expires_at',
            [hash('sha256', $session), $purpose],
        )[0] ?? null;
        if ($row === null || (int) $row['expires_at'] <= ($this->clock)() || !hash_equals((string) $row['user_id'], $user)) {
            return null;
        }

        return Base64Url::decode((string) $row['challenge']);
    }

    private static function assertBound(string $session, string $purpose): void
    {
        if ($session === '') {
            throw new \LogicException('A passkey challenge is bound to a session, and this request has none.');
        }
        if (!\in_array($purpose, [self::REGISTER, self::AUTHENTICATE, self::CONFIRM], true)) {
            throw new \InvalidArgumentException("'{$purpose}' is not a passkey ceremony: register, authenticate or confirm.");
        }
    }
}
