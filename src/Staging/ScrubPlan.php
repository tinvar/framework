<?php

declare(strict_types=1);

namespace Tinvar\Staging;

/**
 * An application's word on every table of its production database, for the
 * staging import (ADR 0090): which are copied and how each of their columns
 * is scrubbed, which are dropped -- credential, session and auth tables,
 * which staging never holds a row of -- and which are neither read nor
 * written, as the migrations' own bookkeeping.
 *
 *   ScrubPlan::create()
 *       ->table('customers', [
 *           'id' => Scrub::keep(),
 *           'email' => Scrub::email(),
 *           'name' => Scrub::pseudonym('Customer'),
 *           'password_hash' => Scrub::unusablePassword(),
 *       ], tenant: 'tenant_id')
 *       ->shared('products', [...])
 *       ->drop('sessions', 'auth_epochs')
 *       ->ignore('migrations', because: 'staging\'s own record of its schema');
 *
 * It is checked against the database, not the other way round: a table the
 * database has and the plan does not, a column with no rule, and a rule
 * for a column there is none of each stop the import before it writes
 * anything (StagingImport). A migration that adds a column stops the next
 * import until someone says what it holds.
 *
 * Every table is said to carry secrets or not: a copied one has a rule for
 * each column, a dropped one is emptied, and an ignored one -- left as it
 * is on both sides -- needs a reason it holds none. A table named like a
 * credential, session or auth table is refused as a copy unless the plan
 * says why, and is never ignored: it is dropped. So is keep() refused for
 * a column named like a secret (Scrub::keep()).
 */
final class ScrubPlan
{
    /**
     * Table names that say they hold credentials, sessions, auth state or
     * something that stands in for them -- an emailed link, an invitation,
     * a nonce, a key. A net under the plan, not the plan: every table is
     * still said to be copied with its columns ruled, dropped, or ignored
     * with a reason it holds no secret.
     */
    public const string CREDENTIAL_TABLES = '/(session|token|credential|passw|auth|otp|mfa|2fa|api_?key|keys?$|secret|handoff'
        . '|recovery|remember|reset|oauth|link|nonce|invit|verif|magic|webauthn|passkey|device|consent|grant)/i';

    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]{0,62}\z/';

    /** @var array<string, array{columns: array<string, Scrub>, tenant: ?string, shared: bool}> */
    private array $tables = [];

    /** @var array<string, true> */
    private array $dropped = [];

    /** @var array<string, true> */
    private array $ignored = [];

    public static function create(): self
    {
        return new self();
    }

    /**
     * A table copied with every column scrubbed by its rule. In an import
     * of one tenant, its rows are that tenant's -- $tenant names the column
     * that says whose -- or it is refused: a table without one would be
     * copied whole, every tenant's rows with it.
     *
     * @param array<string, mixed> $columns every column the table has, each with its Scrub rule
     * @param string|null $because why a table named like a credential table is not one
     */
    public function table(string $name, array $columns, ?string $tenant = null, ?string $because = null): self
    {
        return $this->add($name, $columns, $tenant, false, $because);
    }

    /**
     * A table copied whole even in an import of one tenant -- a catalog, a
     * list of countries -- which no tenant owns a row of.
     *
     * @param array<string, mixed> $columns
     */
    public function shared(string $name, array $columns, ?string $because = null): self
    {
        return $this->add($name, $columns, null, true, $because);
    }

    /** Tables not imported, and emptied in staging: credentials, sessions, auth state, tokens. */
    public function drop(string ...$tables): self
    {
        foreach ($tables as $table) {
            $this->claim($table);
            $this->dropped[$table] = true;
        }

        return $this;
    }

    /**
     * A table neither read nor written: staging's own, as the migrations'
     * record. $because says why it holds no secret, since it is left as it
     * is on both sides. One named like a credential, session or auth table
     * is never ignored: what staging holds of it from before would go on
     * working against the users the import brings in. It is dropped.
     */
    public function ignore(string $table, string $because): self
    {
        $this->claim($table);
        if (trim($because) === '') {
            throw new \InvalidArgumentException("Say why {$table} holds no secret, or drop() it.");
        }
        if (self::credentialTable($table)) {
            throw new \InvalidArgumentException(
                "The table {$table} is named like a credential, session or auth table, and is never ignored: it is dropped.",
            );
        }
        $this->ignored[$table] = true;

        return $this;
    }

    public static function credentialTable(string $table): bool
    {
        return preg_match(self::CREDENTIAL_TABLES, $table) === 1;
    }

    /** @return array<string, array{columns: array<string, Scrub>, tenant: ?string, shared: bool}> in the order declared, which is the order they are written */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return list<string> */
    public function dropped(): array
    {
        return array_keys($this->dropped);
    }

    /** @return list<string> */
    public function ignored(): array
    {
        return array_keys($this->ignored);
    }

    public function declares(string $table): bool
    {
        return isset($this->tables[$table]) || isset($this->dropped[$table]) || isset($this->ignored[$table]);
    }

    public static function identifier(string $name): bool
    {
        return preg_match(self::IDENTIFIER, $name) === 1;
    }

    /** @param array<string, mixed> $columns */
    private function add(string $name, array $columns, ?string $tenant, bool $shared, ?string $because): self
    {
        $this->claim($name);
        if (self::credentialTable($name) && ($because === null || trim($because) === '')) {
            throw new \InvalidArgumentException(
                "The table {$name} is named like a credential, session or auth table, which staging never holds: "
                . 'drop() it, or say why it is not one.',
            );
        }
        if ($columns === []) {
            throw new \InvalidArgumentException("The table {$name} is copied with no columns: name each with its rule.");
        }

        $rules = [];
        foreach ($columns as $column => $rule) {
            if (!self::identifier((string) $column)) {
                throw new \InvalidArgumentException("'{$column}' in {$name} is not a column name the import takes.");
            }
            if (!$rule instanceof Scrub) {
                throw new \InvalidArgumentException("{$name}.{$column} has no Scrub rule.");
            }
            if ($rule->kind === 'keep' && $rule->because === null && preg_match(Scrub::SECRET_NAMES, (string) $column) === 1) {
                throw new \InvalidArgumentException(
                    "{$name}.{$column} is named like a secret, and is kept as it is. Scrub it, or say why it is safe: "
                    . "Scrub::keep(because: '...').",
                );
            }
            $rules[(string) $column] = $rule;
        }

        if ($tenant !== null && (!isset($rules[$tenant]) || $rules[$tenant]->kind !== 'keep')) {
            throw new \InvalidArgumentException("{$name}'s tenant column, {$tenant}, must be one it keeps.");
        }

        $this->tables[$name] = ['columns' => $rules, 'tenant' => $tenant, 'shared' => $shared];

        return $this;
    }

    private function claim(string $table): void
    {
        if (!self::identifier($table)) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the import takes.");
        }
        if ($this->declares($table)) {
            throw new \InvalidArgumentException("The table {$table} is in the plan twice.");
        }
    }
}
