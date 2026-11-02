<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * The shape of a storage key, and of the prefix one is made under.
 *
 * A key is a prefix the application declared, then the tenant it was
 * written for, then a name the framework drew at random, then an extension
 * from the type it sniffed: `avatars/_acme/4f1c…9a.png`. The tenant's
 * segment starts with `_`, which no declared prefix can, so every tenant's
 * files sit apart under each prefix -- isolated in the store as well as
 * checked by the record and the token that name them -- and a key says
 * whose it is (tenantSegmentOf()). A key written with no tenant, or before
 * keys carried one, has none: `avatars/4f1c…9a.png`, read as it always was. Lowercase letters, digits, `_` and `-`, split by
 * single slashes, with one dot before the extension at the very end. No
 * `.` or `..` segment, no backslash, no leading slash, nothing a client
 * chose -- so a key is a path below the store's root on every filesystem
 * and in every object store, and cannot be made to name anything else.
 *
 * Checked by every Storage on every call, not only where keys are made: a
 * key read back from a database row is only as trustworthy as everything
 * that has ever written the row.
 */
final class StorageKey
{
    /** A prefix: one to eight segments. */
    public const string PREFIX = '/^[a-z0-9][a-z0-9_-]{0,63}(?:\/[a-z0-9][a-z0-9_-]{0,63}){0,7}\z/';

    /** A key: a prefix's segments, a tenant's segment at most, then a name and an optional extension. */
    public const string KEY = '/^(?:(?:[a-z0-9][a-z0-9_-]{0,63}|_[a-z0-9_][a-z0-9_-]{0,62})\/){0,9}[a-z0-9][a-z0-9_-]{0,127}(?:\.[a-z0-9]{1,8})?\z/';

    /** A tenant's segment: `_` and the tenant's id, or `__` and a hash of an id no segment can spell. */
    public const string TENANT_SEGMENT = '/^_[a-z0-9_][a-z0-9_-]{0,62}\z/';

    public static function isValid(string $key): bool
    {
        // One tenant's segment at most, after the prefix and before the name.
        return preg_match(self::KEY, $key) === 1 && substr_count('/' . $key, '/_') <= 1;
    }

    /**
     * The segment a key written for $tenant carries: `_acme` for a tenant
     * whose id is a segment already, `__` and a hash for one that is not
     * (uppercase, dots, too long); null for no tenant -- '' or the claim
     * with no tenancy, NONE -- whose keys carry none, as before.
     */
    public static function tenantSegment(string $tenant): ?string
    {
        if ($tenant === '' || $tenant === \Tinvar\Render\TenantClaim::NONE) {
            return null;
        }

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,61}\z/', $tenant) === 1
            ? '_' . $tenant
            : '__' . substr(hash('sha256', $tenant), 0, 40);
    }

    /** The tenant's segment a key carries, or null for a key written for none, or before keys did. */
    public static function tenantSegmentOf(string $key): ?string
    {
        foreach (\array_slice(explode('/', $key), 0, -1) as $segment) {
            if (preg_match(self::TENANT_SEGMENT, $segment) === 1) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Whether a key may be served to a request in $tenant: one carrying no
     * tenant's segment -- written for none, or before keys did, held by
     * its record and token alone -- or one carrying $tenant's.
     */
    public static function belongsTo(string $key, string $tenant): bool
    {
        $segment = self::tenantSegmentOf($key);

        return $segment === null || $segment === self::tenantSegment($tenant);
    }

    public static function isValidPrefix(string $prefix): bool
    {
        return preg_match(self::PREFIX, $prefix) === 1;
    }

    /** @throws StorageException */
    public static function assertValid(string $key): void
    {
        if (!self::isValid($key)) {
            // Not interpolated: a key that fails this may be anything, and
            // the message reaches logs.
            throw new StorageException('A storage key is not shaped as the framework makes them.');
        }
    }

    /**
     * A new key under $prefix, for $tenant: its segment (tenantSegment()),
     * 128 random bits, and the extension of the type the bytes were sniffed
     * as. The client's own name for the file plays no part.
     *
     * @param string $tenant the tenant it is written for -- the tenant in force, or a
     *        record's tenant claim; '' or TenantClaim::NONE for none
     */
    public static function generate(string $prefix, string $extension, string $tenant = ''): string
    {
        if (!self::isValidPrefix($prefix)) {
            throw new \InvalidArgumentException("'{$prefix}' is not a storage key prefix.");
        }

        $segment = self::tenantSegment($tenant);
        $key = $prefix . '/' . ($segment === null ? '' : $segment . '/') . bin2hex(random_bytes(16)) . ($extension === '' ? '' : '.' . $extension);
        self::assertValid($key);

        return $key;
    }
}
