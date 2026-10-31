<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Emailed links' records in a table of the application's database, so the
 * worker that sends a link and whichever server it is opened on read one
 * record of it.
 *
 * A link is used by one UPDATE that names everything that must still hold
 * -- unused, unrevoked, unexpired, of its purpose and tenant -- so of two
 * requests racing with one link, the database lets one change the row and
 * the other changes nothing (ADR 0076). Written on its own statements, not
 * the Orm's; inside the caller's transaction it is part of it, so a
 * password reset that fails after it gives the link back.
 */
final readonly class DatabaseEmailLinkStore implements EmailLinkStore
{
    private const string COLUMNS = 'id, purpose, tenant, origin, user_id, data, created_at, expires_at, used_at, revoked_at';

    public function __construct(private Connection $connection, private string $table = 'email_links')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
    }

    /** The store's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'email_links', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $links) use ($table): void {
            $links->string('id', 64)->primary();
            // SHA-256 of the token's secret, hex: how a link that arrives is found.
            $links->string('secret_hash', 64);
            $links->string('purpose', 32);
            $links->string('tenant', 191);
            $links->string('origin', 255);
            $links->string('user_id', 191);
            $links->text('data');
            $links->bigInteger('created_at');
            $links->bigInteger('expires_at');
            $links->bigInteger('used_at')->nullable();
            $links->bigInteger('revoked_at')->nullable();
            $links->unique(['secret_hash'], "{$table}_secret");
            // What a list of pending invites asks, and what pruning asks.
            $links->index(['tenant', 'purpose', 'expires_at'], "{$table}_open");
            $links->index(['expires_at'], "{$table}_expiry");
        }, $ifNotExists);
    }

    public function add(EmailLink $link, string $secretHash): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (" . self::COLUMNS . ', secret_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $link->id,
                $link->purpose,
                $link->tenant,
                $link->origin,
                $link->user,
                json_encode((object) $link->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $link->createdAt,
                $link->expiresAt,
                $link->usedAt,
                $link->revokedAt,
                $secretHash,
            ],
        );
    }

    public function find(string $secretHash): ?EmailLink
    {
        $row = $this->connection->select(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE secret_hash = ?",
            [$secretHash],
        )[0] ?? null;

        return $row === null ? null : self::hydrate($row);
    }

    public function consume(string $secretHash, string $purpose, string $tenant, int $now): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET used_at = ? WHERE secret_hash = ? AND purpose = ? AND tenant = ? "
            . 'AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
            [$now, $secretHash, $purpose, $tenant, $now],
        ) === 1;
    }

    public function revoke(string $id, string $purpose, string $tenant, int $now): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET revoked_at = ? WHERE id = ? AND purpose = ? AND tenant = ? "
            . 'AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
            [$now, $id, $purpose, $tenant, $now],
        ) === 1;
    }

    public function byId(string $id, string $purpose, string $tenant): ?EmailLink
    {
        $row = $this->connection->select(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE id = ? AND purpose = ? AND tenant = ?",
            [$id, $purpose, $tenant],
        )[0] ?? null;

        return $row === null ? null : self::hydrate($row);
    }

    public function open(string $purpose, string $tenant, int $now, int $limit, array $data = []): array
    {
        // Each value found in the data column by its JSON, written as add()
        // writes it: a key and its value side by side, which no other value
        // can spell, since a value's own quotes are escaped. LIKE folds case
        // on SQLite and MySQL, so what comes back is held to $data exactly
        // too.
        $matching = '';
        $bindings = [$tenant, $purpose, $now];
        foreach ($data as $key => $value) {
            $pair = substr(json_encode([$key => $value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
            $matching .= " AND data LIKE ? ESCAPE '!'";
            $bindings[] = '%' . strtr($pair, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE tenant = ? AND purpose = ? AND expires_at > ? "
            . "AND used_at IS NULL AND revoked_at IS NULL{$matching} ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit),
            $bindings,
        );

        return array_values(array_filter(
            array_map(self::hydrate(...), $rows),
            static fn (EmailLink $link): bool => array_intersect_assoc($data, $link->data) === $data,
        ));
    }

    public function prune(int $before, int $limit): int
    {
        // A subquery for the bound: SQLite takes LIMIT on a DELETE only when
        // compiled to, and MySQL refuses one naming its own table, so the
        // ids are read first.
        $ids = array_map(
            static fn (array $row): string => \is_string($row['id'] ?? null) ? $row['id'] : '',
            $this->connection->select(
                "SELECT id FROM {$this->table} WHERE expires_at < ? ORDER BY expires_at LIMIT " . max(1, $limit),
                [$before],
            ),
        );
        if ($ids === []) {
            return 0;
        }

        return $this->connection->execute(
            "DELETE FROM {$this->table} WHERE id IN (" . implode(', ', array_fill(0, \count($ids), '?')) . ')',
            $ids,
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): EmailLink
    {
        $decoded = \is_string($row['data'] ?? null) ? json_decode($row['data'], true) : null;
        $data = [];
        foreach (\is_array($decoded) ? $decoded : [] as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $data[$key] = $value;
            }
        }

        return new EmailLink(
            id: self::string($row['id'] ?? null),
            purpose: self::string($row['purpose'] ?? null),
            tenant: self::string($row['tenant'] ?? null),
            origin: self::string($row['origin'] ?? null),
            user: self::string($row['user_id'] ?? null),
            data: $data,
            createdAt: self::int($row['created_at'] ?? 0),
            expiresAt: self::int($row['expires_at'] ?? 0),
            usedAt: ($row['used_at'] ?? null) === null ? null : self::int($row['used_at']),
            revokedAt: ($row['revoked_at'] ?? null) === null ? null : self::int($row['revoked_at']),
        );
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : '');
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : (\is_string($value) && is_numeric($value) ? (int) $value : 0);
    }
}
