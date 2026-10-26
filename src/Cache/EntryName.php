<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * What a shared store files a key or a tag under.
 *
 * A short, plain key is kept as it is, so an operator looking at the table
 * or at APCu sees the query cache's own `q:...` names. Anything else is
 * hashed: a key may be long, and a tag carries its tenant's id and a NUL
 * (TagNamespace), which a Postgres text column cannot hold at all. The
 * hashed form starts with `#`, which the plain form never does, so no key
 * can be chosen to land on another's hash.
 *
 * SHA-256, as the query cache's own key is: two keys sharing a name is one
 * caller reading another's result, and two tags sharing one is one tenant's
 * write dropping another's entries -- a fast hash makes no promise against
 * someone looking for either.
 *
 * @internal for ApcuStore and PdoStore
 */
final class EntryName
{
    /** Longer than any name this returns: 65, for a hashed one. */
    public const int MAX_LENGTH = 100;

    public static function of(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_.:\-]{1,64}\z/', $key) === 1 ? $key : '#' . hash('sha256', $key);
    }
}
