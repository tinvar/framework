<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * The shape of a storage key, and of the prefix one is made under.
 *
 * A key is a prefix the application declared, then a name the framework
 * drew at random, then an extension from the type it sniffed:
 * `avatars/4f1c…9a.png`. Lowercase letters, digits, `_` and `-`, split by
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

    /** A key: a prefix's segments, then a name and an optional extension. */
    public const string KEY = '/^(?:[a-z0-9][a-z0-9_-]{0,63}\/){0,8}[a-z0-9][a-z0-9_-]{0,127}(?:\.[a-z0-9]{1,8})?\z/';

    public static function isValid(string $key): bool
    {
        return preg_match(self::KEY, $key) === 1;
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
     * A new key under $prefix: 128 random bits, and the extension of the
     * type the bytes were sniffed as. The client's own name for the file
     * plays no part.
     */
    public static function generate(string $prefix, string $extension): string
    {
        if (!self::isValidPrefix($prefix)) {
            throw new \InvalidArgumentException("'{$prefix}' is not a storage key prefix.");
        }

        $key = $prefix . '/' . bin2hex(random_bytes(16)) . ($extension === '' ? '' : '.' . $extension);
        self::assertValid($key);

        return $key;
    }
}
