<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;

/**
 * API tokens in a table of the application's database, so every worker and
 * server knows each one the moment it is issued and forgets it the moment
 * it is revoked.
 *
 * A row holds the SHA-256 of the token's secret, never the secret: a copy
 * of the table signs nobody in. Each moment is stored as the ORM stores
 * one (ADR 0145) -- timestamptz on Postgres, DATETIME(6) as UTC on MySQL,
 * fixed-width ISO 8601 on SQLite -- and read back as a CarbonImmutable in
 * UTC.
 *
 * Every statement about a user's tokens names the tenant (ApiTokenStore).
 * A use is recorded at most once a minute (ApiTokens::USE_RECORDED_EVERY):
 * an UPDATE that names the moment it must be older than, so of two workers
 * recording one token's use, one writes.
 */
final readonly class DatabaseApiTokens implements ApiTokenStore
{
    private TimestampStorage $moments;

    public function __construct(private Connection $connection, private string $table = 'api_tokens')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
        $this->moments = TimestampStorage::for($connection);
    }

    /** The store's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'api_tokens', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $tokens) use ($table): void {
            $tokens->string('id', 32)->primary();
            $tokens->string('tenant');
            $tokens->string('panel', 64);
            $tokens->string('user_id');
            $tokens->string('auth_epoch');
            $tokens->string('host');
            $tokens->string('name', ApiToken::MAX_NAME_LENGTH);
            $tokens->text('scopes');
            $tokens->string('secret_digest', 64);
            $tokens->timestamp('created_at');
            $tokens->timestamp('expires_at')->nullable();
            $tokens->timestamp('last_used_at')->nullable();
            $tokens->timestamp('revoked_at')->nullable();
            // What a user's settings page lists them by.
            $tokens->index(['tenant', 'user_id'], "{$table}_user");
        }, $ifNotExists);
    }

    public function insert(ApiToken $token, string $digest): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (id, tenant, panel, user_id, auth_epoch, host, name, scopes, secret_digest, created_at, expires_at, last_used_at, revoked_at) "
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)',
            [
                $token->id,
                $token->tenant,
                $token->panel,
                $token->user,
                $token->epoch,
                $token->host,
                $token->name,
                json_encode($token->scopes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
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

    public function forUser(string $tenant, string $panel, string $user): array
    {
        return array_map(self::token(...), $this->connection->select(
            "SELECT * FROM {$this->table} WHERE tenant = ? AND panel = ? AND user_id = ? ORDER BY created_at DESC, id DESC",
            [$tenant, $panel, $user],
        ));
    }

    public function revoke(string $tenant, string $user, string $id, CarbonImmutable $at): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET revoked_at = ? WHERE tenant = ? AND user_id = ? AND id = ? AND revoked_at IS NULL",
            [$this->moments->format($at), $tenant, $user, $id],
        ) > 0;
    }

    public function touch(string $id, CarbonImmutable $at, CarbonImmutable $since): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at <= ?)",
            [$this->moments->format($at), $id, $this->moments->format($since)],
        );
    }

    /** @param array<string, mixed> $row */
    private static function token(array $row): ApiToken
    {
        $scopes = json_decode(self::string($row['scopes'] ?? '[]'), true);

        return new ApiToken(
            id: self::string($row['id'] ?? ''),
            name: self::string($row['name'] ?? ''),
            user: self::string($row['user_id'] ?? ''),
            epoch: self::string($row['auth_epoch'] ?? ''),
            tenant: self::string($row['tenant'] ?? ''),
            panel: self::string($row['panel'] ?? ''),
            host: self::string($row['host'] ?? ''),
            // A row is the store's own, but read as untrusted all the same:
            // anything that is not a list of strings grants nothing.
            scopes: \is_array($scopes) && array_is_list($scopes) ? array_values(array_filter($scopes, \is_string(...))) : [],
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
