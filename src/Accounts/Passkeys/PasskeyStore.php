<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;
use Tinvar\Support\Uuid;

/**
 * The passkeys each account keeps: the credential's id and public key, the
 * sign count it last reported, and what the account calls it.
 *
 * A credential is found by a hash of its id, unique across every account:
 * a credential id two accounts claimed would sign in as whichever was
 * found first, so the second registration is refused. Nothing here is a
 * secret -- a public key opens nothing -- but the table is the account's,
 * and a removal is held to the account that asks (remove()).
 */
final readonly class PasskeyStore
{
    /** The most passkeys one account keeps. */
    public const int MAX_PER_ACCOUNT = 20;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private Connection $connection, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /** @param class-string<Model> $userModel */
    public static function define(Schema $schema, string $userModel, bool $ifNotExists = false): void
    {
        $schema->create('passkeys', static function (Table $passkeys) use ($userModel): void {
            $passkeys->uuid('id')->primary();
            $passkeys->foreign($userModel, 'user_id')->cascadeOnDelete();
            // SHA-256 of the credential's id, hex: how one that signs in is found.
            $passkeys->string('credential_hash', 64);
            $passkeys->text('credential_id');
            $passkeys->string('user_handle', 64);
            $passkeys->text('public_key');
            $passkeys->bigInteger('sign_count');
            $passkeys->string('name', 100);
            $passkeys->string('transports', 255);
            $passkeys->boolean('backed_up');
            $passkeys->timestamp('created_at');
            $passkeys->timestamp('last_used_at')->nullable();
            $passkeys->unique(['credential_hash'], 'passkeys_credential');
            $passkeys->index(['user_id'], 'passkeys_user');
        }, $ifNotExists);
    }

    /**
     * Keeps a verified credential for $userId; false when the credential is
     * already kept, by this account or another.
     *
     * @param list<string> $transports
     */
    public function add(string $userId, string $userHandle, VerifiedRegistration $credential, string $name, array $transports): bool
    {
        if ($this->byCredentialId($credential->credentialId) !== null) {
            return false;
        }
        $transports = array_values(array_filter($transports, static fn (string $t): bool => preg_match('/^[a-z-]{1,16}\z/', $t) === 1));

        $this->connection->execute(
            'INSERT INTO passkeys (id, user_id, credential_hash, credential_id, user_handle, public_key, sign_count, name, transports, backed_up, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Uuid::v7(),
                $userId,
                hash('sha256', $credential->credentialId),
                Base64Url::encode($credential->credentialId),
                Base64Url::encode($userHandle),
                Base64Url::encode($credential->publicKey),
                $credential->signCount,
                mb_substr($name, 0, 100),
                implode(',', \array_slice($transports, 0, 8)),
                $credential->backedUp,
                $this->moment(),
            ],
        );

        return true;
    }

    public function byCredentialId(string $credentialId): ?Passkey
    {
        $row = $this->connection->select('SELECT * FROM passkeys WHERE credential_hash = ?', [hash('sha256', $credentialId)])[0] ?? null;

        return $row === null ? null : self::passkey($row);
    }

    /** @return list<Passkey> $userId's passkeys, oldest first */
    public function of(string $userId): array
    {
        return array_map(
            self::passkey(...),
            $this->connection->select('SELECT * FROM passkeys WHERE user_id = ? ORDER BY created_at, id', [$userId]),
        );
    }

    public function count(string $userId): int
    {
        return (int) ($this->connection->select('SELECT COUNT(*) AS n FROM passkeys WHERE user_id = ?', [$userId])[0]['n'] ?? 0);
    }

    /**
     * Records a sign-in with the passkey: its new sign count, and when. Only
     * from the count it was read at, so of two sign-ins racing with one
     * response, one wins.
     *
     * MySQL counts the rows an UPDATE changed, not the rows it matched, and
     * a passkey that keeps no count -- 0 every time, as synced passkeys
     * report -- used again in the second it was last used changes nothing.
     * There the passkey is asked after when the count stays put and no row
     * was counted: still kept at the count it was read at (ADR 0172). The
     * read locks (FOR SHARE), so it sees the row as it stands, not as a
     * transaction already open first read it. A count never goes back, and
     * an id is never kept again, so a row it finds is the one the UPDATE
     * matched.
     */
    public function used(Passkey $passkey, VerifiedAssertion $assertion): bool
    {
        return $this->connection->execute(
            'UPDATE passkeys SET sign_count = ?, backed_up = ?, last_used_at = ? WHERE id = ? AND sign_count = ?',
            [$assertion->signCount, $assertion->backedUp, $this->moment(), $passkey->id, $passkey->signCount],
        ) === 1 || ($assertion->signCount === $passkey->signCount && $this->onMysql() && $this->connection->select(
            'SELECT 1 FROM passkeys WHERE id = ? AND sign_count = ? FOR SHARE',
            [$passkey->id, $passkey->signCount],
        ) !== []);
    }

    /** Removes the passkey $id if it is $userId's; false when it is not, or is gone. */
    public function remove(string $userId, string $id): bool
    {
        return $this->connection->execute('DELETE FROM passkeys WHERE id = ? AND user_id = ?', [$id, $userId]) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function passkey(array $row): Passkey
    {
        $transports = (string) $row['transports'];

        return new Passkey(
            id: (string) $row['id'],
            userId: (string) $row['user_id'],
            credentialId: (string) Base64Url::decode((string) $row['credential_id']),
            userHandle: (string) Base64Url::decode((string) $row['user_handle']),
            publicKey: (string) Base64Url::decode((string) $row['public_key']),
            signCount: (int) $row['sign_count'],
            name: (string) $row['name'],
            transports: $transports === '' ? [] : explode(',', $transports),
            backedUp: (bool) $row['backed_up'],
            createdAt: self::read($row['created_at']) ?? CarbonImmutable::createFromTimestampUTC(0),
            lastUsedAt: self::read($row['last_used_at']),
        );
    }

    private static function read(mixed $moment): ?CarbonImmutable
    {
        return \is_string($moment) && $moment !== '' ? new CarbonImmutable($moment, 'UTC')->utc() : null;
    }

    private function onMysql(): bool
    {
        return $this->connection instanceof NamesDriver && $this->connection->driver() === 'mysql';
    }

    private function moment(): string
    {
        return TimestampStorage::for($this->connection)->format(CarbonImmutable::createFromTimestampUTC(($this->clock)()));
    }
}
