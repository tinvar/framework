<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Remembered consents in a table of the platform's database: one row per
 * user and domain.
 *
 * Every read names the user, the epoch, the tenant and the panel beside the
 * domain, so a row outlives none of them. Taking one back is durable: a
 * revocation a power cut took back would be a domain the person said to be
 * asked about again, signed into without asking. Keeping one is not -- one
 * lost is a screen shown once more.
 */
final readonly class DatabaseRememberedConsents implements RememberedConsents
{
    public function __construct(
        private Connection $connection,
        private string $table = 'handoff_consents',
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
    }

    /** The store's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'handoff_consents', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $consents): void {
            $consents->string('user_id');
            $consents->string('origin');
            $consents->string('auth_epoch');
            $consents->string('tenant');
            $consents->string('panel');
            $consents->bigInteger('remembered_at');
            $consents->bigInteger('expires_at');
            $consents->primary(['user_id', 'origin']);
        }, $ifNotExists);
    }

    public function remembers(string $user, string $epoch, string $tenant, string $panel, string $origin, int $now): bool
    {
        return $this->connection->select(
            "SELECT 1 AS hit FROM {$this->table} WHERE user_id = ? AND origin = ? AND auth_epoch = ? AND tenant = ? AND panel = ? AND expires_at > ?",
            [$user, $origin, $epoch, $tenant, $panel, $now],
        ) !== [];
    }

    public function remember(string $user, string $epoch, string $tenant, string $panel, string $origin, int $now): void
    {
        if ($user === '') {
            throw new \InvalidArgumentException('A consent is remembered for somebody.');
        }
        $this->connection->transaction(function () use ($user, $epoch, $tenant, $panel, $origin, $now): void {
            $this->connection->execute("DELETE FROM {$this->table} WHERE user_id = ? AND origin = ?", [$user, $origin]);
            $this->connection->execute(
                "INSERT INTO {$this->table} (user_id, origin, auth_epoch, tenant, panel, remembered_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$user, $origin, $epoch, $tenant, $panel, $now, $now + self::MAX_AGE],
            );
        });
    }

    public function forget(string $user, string $origin): void
    {
        $this->connection->durableTransaction(fn (): int => $this->connection->execute(
            "DELETE FROM {$this->table} WHERE user_id = ? AND origin = ?",
            [$user, $origin],
        ));
    }

    public function forgetUser(string $user): void
    {
        $this->connection->durableTransaction(fn (): int => $this->connection->execute(
            "DELETE FROM {$this->table} WHERE user_id = ?",
            [$user],
        ));
    }

    /**
     * In a transaction of the caller's, or a savepoint inside one: a lapse
     * forgets the domain inside the transaction that lapsed it, which may be
     * an action's, so this is not the durable transaction forget() is. The
     * realm lookup has stopped answering for the host by then, so no
     * handoff reaches a screen this row would skip, whether or not it is on
     * disk yet.
     */
    public function forgetOrigin(string $origin): void
    {
        $this->connection->transaction(fn (): int => $this->connection->execute(
            "DELETE FROM {$this->table} WHERE origin = ?",
            [$origin],
        ));
    }

    public function forUser(string $user, string $epoch, int $now): array
    {
        $string = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';
        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;

        return array_map(static fn (array $row): RememberedConsent => new RememberedConsent(
            $string($row['origin'] ?? ''),
            $string($row['tenant'] ?? ''),
            $string($row['panel'] ?? ''),
            $int($row['remembered_at'] ?? 0),
            $int($row['expires_at'] ?? 0),
        ), $this->connection->select(
            "SELECT origin, tenant, panel, remembered_at, expires_at FROM {$this->table} WHERE user_id = ? AND auth_epoch = ? AND expires_at > ? ORDER BY remembered_at, origin",
            [$user, $epoch, $now],
        ));
    }
}
