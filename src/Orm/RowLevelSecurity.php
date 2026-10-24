<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Postgres row-level security for #[TenantScoped] tables: the policy that
 * reads the session variable PdoConnection keeps (see TenantSetting).
 *
 * The ORM already filters every query by the tenant and fails closed with
 * no tenant. This is the second layer, for whatever goes around the ORM --
 * raw SQL through connection(), a `whereRaw` subquery, a report someone
 * wrote by hand -- that forgot the predicate: the database still returns
 * only the tenant's rows, and none at all when no tenant is set.
 *
 * A migration applies it (ADR 0029): Table::tenant() on a table it makes,
 * Schema::tenantPolicy() on one that has its tenant column already, and
 * `make:migration` writes whichever a #[TenantScoped] table lacks.
 *
 * The policy is FORCEd, so the table's owner -- often the
 * application's own role -- is held to it too; a superuser or a role with
 * BYPASSRLS is not, whatever this does, so the application must not
 * connect as one.
 *
 * acrossTenants() lifts the policy as it lifts the ORM's filter, by a
 * second variable. It is a guard against a forgotten predicate, not against
 * SQL injection: injected SQL can call set_config() as well as this class
 * can.
 */
final class RowLevelSecurity
{
    /** The session variable holding the tenant in force; '' for none. */
    public const string TENANT_SETTING = 'app.tenant';

    /** 'on' while acrossTenants() has lifted scoping; 'off' otherwise. */
    public const string ACROSS_SETTING = 'app.across_tenants';

    /** The policy's name on every table, so it can be dropped and made again. */
    public const string POLICY = 'tinvar_tenant';

    /**
     * The statements that turn the policy on for one table.
     *
     * @param string $type the tenant column's type, which the setting -- text -- is cast
     *        to, so the comparison uses the column's index rather than casting every row
     * @return list<string>
     */
    public static function policy(string $table, string $column = 'tenant_id', string $type = 'text'): array
    {
        $table = self::identifier($table, 'table');
        $column = self::identifier($column, 'column');
        if (preg_match('/^[a-z][a-z0-9_ ]{0,62}\z/', $type) !== 1) {
            throw new \InvalidArgumentException("'{$type}' is not a column type this can cast to.");
        }

        // NULLIF: no tenant is '', which cast to a uuid is an error rather
        // than a value matching nothing. NULL compares as unknown, and a
        // policy reading unknown matches no row.
        $predicate = \sprintf(
            "current_setting('%s', true) = 'on' OR %s = NULLIF(current_setting('%s', true), '')::%s",
            self::ACROSS_SETTING,
            $column,
            self::TENANT_SETTING,
            $type,
        );

        return [
            "ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE {$table} FORCE ROW LEVEL SECURITY",
            'DROP POLICY IF EXISTS ' . self::POLICY . " ON {$table}",
            'CREATE POLICY ' . self::POLICY . " ON {$table} USING ({$predicate}) WITH CHECK ({$predicate})",
        ];
    }

    /**
     * The policy for a model's table and tenant column.
     *
     * @param class-string<Model> $model
     * @return list<string>
     */
    public static function forModel(string $model, string $type = 'text'): array
    {
        $meta = $model::meta();
        if ($meta->tenantColumn === null) {
            throw new \InvalidArgumentException("{$model} is not #[TenantScoped]; there is no tenant column to hold it to.");
        }

        return self::policy($meta->table, $meta->tenantColumn, $type);
    }

    /** A plain or schema-qualified identifier, quoted. */
    private static function identifier(string $name, string $what): string
    {
        $parts = explode('.', $name);
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $part) !== 1) {
                throw new \InvalidArgumentException("'{$name}' is not a {$what} name.");
            }
        }

        return implode('.', array_map(static fn (string $part): string => '"' . $part . '"', $parts));
    }
}
