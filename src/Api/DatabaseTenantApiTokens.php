<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;

/**
 * Tenant API tokens in a table of the application's database (ADR 0193),
 * and each tenant's token epoch in a second, so every worker and server
 * knows each token the moment it is issued and forgets it the moment it is
 * revoked, or its tenant's tokens are ended.
 *
 * A row holds the SHA-256 of the token's secret, never the secret: a copy
 * of the table signs nothing in. Each moment is stored as the ORM stores
 * one (ADR 0145) and read back as a CarbonImmutable in UTC, as
 * DatabaseApiTokens does. Every statement about a tenant's tokens names the
 * tenant. A tenant with no epoch row is at epoch 0; advance() moves it with
 * one statement that makes the row or adds to it, so two at once both land.
 */
final readonly class DatabaseTenantApiTokens implements TenantApiTokenStore
{
    private TimestampStorage $moments;

    private string $epochs;

    public function __construct(private Connection $connection, private string $table = 'tenant_api_tokens')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,55}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
        $this->epochs = "{$table}_epochs";
        $this->moments = TimestampStorage::for($connection);
    }

    /** The store's two tables, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'tenant_api_tokens', bool $ifNotExists = false): void
    {
        $store = new self($schema->connection(), $table);

        $schema->create($table, static function (Table $tokens) use ($table): void {
            $tokens->string('id', 32)->primary();
            $tokens->string('tenant');
            $tokens->string('panel', 64);
            $tokens->string('host');
            $tokens->string('name', ApiToken::MAX_NAME_LENGTH);
            $tokens->text('scopes');
            $tokens->string('token_epoch', 32);
            $tokens->string('created_by');
            $tokens->string('secret_digest', 64);
            $tokens->timestamp('created_at');
            $tokens->timestamp('expires_at')->nullable();
            $tokens->timestamp('last_used_at')->nullable();
            $tokens->timestamp('revoked_at')->nullable();
            // What the tenant's page lists them by.
            $tokens->index(['tenant', 'panel'], "{$table}_tenant");
        }, $ifNotExists);

        $schema->create($store->epochs, static function (Table $epochs): void {
            $epochs->string('tenant')->primary();
            $epochs->bigInteger('epoch');
        }, $ifNotExists);
    }

    public function insert(TenantApiToken $token, string $digest): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (id, tenant, panel, host, name, scopes, token_epoch, created_by, secret_digest, created_at, expires_at, last_used_at, revoked_at) "
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)',
            [
                $token->id,
                $token->tenant,
                $token->panel,
                $token->host,
                $token->name,
                json_encode($token->scopes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $token->epoch,
                $token->createdBy,
                $digest,
                $this->moments->format($token->createdAt),
                $token->expiresAt === null ? null : $this->moments->format($token->expiresAt),
            ],
        );
    }

    public function find(string $id): ?array
    {
        $rows = $this->connection->select("SELECT * FROM {$this->table} WHERE id = ?", [$id]);
        if ($rows === []) {
            return null;
        }

        return [self::token($rows[0]), self::string($rows[0]['secret_digest'] ?? '')];
    }

    public function forTenant(string $tenant, string $panel): array
    {
        return array_map(self::token(...), $this->connection->select(
            "SELECT * FROM {$this->table} WHERE tenant = ? AND panel = ? ORDER BY created_at DESC, id DESC",
            [$tenant, $panel],
        ));
    }

    public function revoke(string $tenant, string $panel, string $id, CarbonImmutable $at): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET revoked_at = ? WHERE tenant = ? AND panel = ? AND id = ? AND revoked_at IS NULL",
            [$this->moments->format($at), $tenant, $panel, $id],
        ) > 0;
    }

    /**
     * The tenant's epoch row, made where there is none and written to as it
     * stands: a write takes the row's lock on Postgres and MySQL, and the
     * database's on SQLite, held until the transaction commits.
     */
    public function exclusively(string $tenant, \Closure $work): mixed
    {
        return $this->connection->transaction(function () use ($tenant, $work): mixed {
            $this->connection->execute(
                $this->connection instanceof NamesDriver && $this->connection->driver() === 'mysql'
                    ? "INSERT INTO {$this->epochs} (tenant, epoch) VALUES (?, 0) ON DUPLICATE KEY UPDATE epoch = epoch"
                    : "INSERT INTO {$this->epochs} (tenant, epoch) VALUES (?, 0) ON CONFLICT (tenant) DO UPDATE SET epoch = {$this->epochs}.epoch",
                [$tenant],
            );

            return $work();
        });
    }

    public function touch(string $id, CarbonImmutable $at, CarbonImmutable $since): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at <= ?)",
            [$this->moments->format($at), $id, $this->moments->format($since)],
        );
    }

    public function epoch(string $tenant): string
    {
        $epoch = $this->connection->select("SELECT epoch FROM {$this->epochs} WHERE tenant = ?", [$tenant])[0]['epoch'] ?? 0;

        return (string) (\is_int($epoch) || \is_string($epoch) ? $epoch : 0);
    }

    public function advance(string $tenant): void
    {
        $this->connection->execute(
            $this->connection instanceof NamesDriver && $this->connection->driver() === 'mysql'
                ? "INSERT INTO {$this->epochs} (tenant, epoch) VALUES (?, 1) ON DUPLICATE KEY UPDATE epoch = epoch + 1"
                : "INSERT INTO {$this->epochs} (tenant, epoch) VALUES (?, 1) ON CONFLICT (tenant) DO UPDATE SET epoch = {$this->epochs}.epoch + 1",
            [$tenant],
        );
    }

    /** @param array<string, mixed> $row */
    private static function token(array $row): TenantApiToken
    {
        $scopes = json_decode(self::string($row['scopes'] ?? '[]'), true);

        return new TenantApiToken(
            id: self::string($row['id'] ?? ''),
            name: self::string($row['name'] ?? ''),
            tenant: self::string($row['tenant'] ?? ''),
            panel: self::string($row['panel'] ?? ''),
            host: self::string($row['host'] ?? ''),
            // A row is the store's own, but read as untrusted all the same:
            // anything that is not a list of strings grants nothing.
            scopes: \is_array($scopes) && array_is_list($scopes) ? array_values(array_filter($scopes, \is_string(...))) : [],
            epoch: self::string($row['token_epoch'] ?? ''),
            createdBy: self::string($row['created_by'] ?? ''),
            createdAt: self::moment($row['created_at'] ?? null) ?? CarbonImmutable::createFromTimestampUTC(0),
            expiresAt: self::moment($row['expires_at'] ?? null),
            lastUsedAt: self::moment($row['last_used_at'] ?? null),
            revokedAt: self::moment($row['revoked_at'] ?? null),
        );
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** A moment as any dialect returns one, in UTC; null for none. */
    private static function moment(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::instance(new \DateTimeImmutable(self::string($value), new \DateTimeZone('UTC')))->setTimezone('UTC');
    }
}
