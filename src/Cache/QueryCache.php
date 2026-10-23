<?php

declare(strict_types=1);

namespace Tinvar\Cache;

use Tinvar\Attributes\Query;
use Tinvar\Auth\AuthContext;
use Tinvar\Orm\Model;

/**
 * The query cache.
 *
 * tinvar-spec.md, "Query cache":
 *   "The default cache key is the query name, its arguments, the tenant, the
 *    user, the panel and its effective scope, the locale, and the feature flags
 *    the query read."
 *   "#[Query(cache: '1m', shared: true)] drops the user from the key."
 *   "Declarative #[Authorize] runs before the lookup, so a cache hit never
 *    skips it."
 *
 * The last line is the important one, and it is a property of the caller: this
 * class is only ever consulted after the gate has answered.
 */
final class QueryCache
{
    /** @var list<\Throwable> */
    private array $failures = [];

    public function __construct(
        private readonly Store $store,
        private readonly AuthContext $auth,
        private readonly string $panel = 'default',
        private readonly string $locale = 'en',
    ) {}

    /**
     * Bumped whenever the key's composition changes, so old entries cannot be
     * read under a new scheme. Adding the feature-flag component will need it.
     */
    private const string KEY_SCHEMA = 'v1';

    public function enabledFor(Query $declaration): bool
    {
        return $declaration->cache !== null;
    }

    /**
     * @param array<string, mixed> $arguments resolved arguments, models included
     * @return array{hit: bool, value: mixed}
     */
    public function get(string $name, Query $declaration, array $arguments, string $tenant): array
    {
        if (!$this->enabledFor($declaration)) {
            return ['hit' => false, 'value' => null];
        }

        $key = $this->keyOrNull($name, $declaration, $arguments, $tenant);

        // Unkeyable means uncacheable, never loosely keyed.
        return $key === null ? ['hit' => false, 'value' => null] : $this->store->get($key);
    }

    /** @param array<string, mixed> $arguments */
    public function put(
        string $name,
        Query $declaration,
        array $arguments,
        string $tenant,
        mixed $value,
    ): void {
        if (!$this->enabledFor($declaration)) {
            return;
        }

        $key = $this->keyOrNull($name, $declaration, $arguments, $tenant);
        if ($key === null) {
            return;
        }

        try {
            $ttl = Duration::seconds((string) $declaration->cache);
            $tags = $this->tagsFor($declaration, $arguments, $tenant);
        } catch (\Throwable $e) {
            // A mistyped tag closure or TTL is a declaration bug, and it is
            // only reachable on a miss -- after the body has run. Losing every
            // other result in the tick over it would be the wrong trade; not
            // caching this one is the right one.
            $this->failures[] = $e;

            return;
        }

        $this->store->put($key, $value, $ttl, $tags);
    }

    /**
     * Invalidate every cached entry carrying one of these tags.
     *
     * $tenant has no default on purpose. put() namespaces tags with the LIVE
     * tenant, so a defaulted '' silently invalidated nothing at all -- the
     * caller must say whose entries it means, and ActionResult carries only
     * strings.
     *
     * @param list<string> $tags
     * @return int entries invalidated
     */
    public function forgetTags(array $tags, string $tenant): int
    {
        return $this->store->forgetTags(self::namespaced($tags, $tenant));
    }

    /**
     * Declaration problems seen while caching, for the dev toolbar.
     *
     * They do not fail the request -- the data was fine, only the caching of
     * it was not -- but they must not be silent either.
     *
     * @return list<\Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Tags come from the attribute's closure, which receives the query's
     * arguments. A model argument is passed as the model, so a tag can read
     * its id without the closure doing a lookup.
     *
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    public function tagsFor(Query $declaration, array $arguments, string $tenant = ''): array
    {
        if ($declaration->tags === null) {
            return [];
        }

        $tags = ($declaration->tags)(...$arguments);

        $strings = [];
        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                // strval on a nested array yields the literal "Array", which
                // would merge unrelated entries under one tag.
                throw new \LogicException(
                    'A #[Query(tags:)] closure must return strings, got ' . get_debug_type($tag) . '.'
                );
            }
            $strings[] = $tag;
        }

        return self::namespaced($strings, $tenant);
    }

    /**
     * Tags are namespaced by tenant, as the key is.
     *
     * A flat namespace lets a write in one tenant drop another's entries --
     * not a disclosure, but a cross-tenant side channel and a cheap way to
     * defeat the cache for everyone.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    private static function namespaced(array $tags, string $tenant): array
    {
        return array_values(array_map(
            static fn (string $tag): string => $tenant . "\0" . $tag,
            $tags,
        ));
    }

    /** @param array<string, mixed> $arguments */
    private function keyOrNull(string $name, Query $declaration, array $arguments, string $tenant): ?string
    {
        try {
            return $this->key($name, $declaration, $arguments, $tenant);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Everything that can change the answer goes in the key.
     *
     * Dropping any of these is a cross-user or cross-tenant read: two callers
     * whose results differ must not share an entry.
     *
     * @param array<string, mixed> $arguments
     */
    public function key(string $name, Query $declaration, array $arguments, string $tenant): string
    {
        $parts = [
            'v' => self::KEY_SCHEMA,
            'q' => $name,
            'a' => self::canonical($arguments),
            't' => $tenant,
            'p' => $this->panel,
            'l' => $this->locale,
        ];

        // "shared: true drops the user from the key." Safe only when the body
        // cannot read the current user, which BuildRules checks -- by type
        // closure for injected dependencies, textually for direct calls, and
        // not at all through the wider call graph. Its docblock says so.
        if (!$declaration->shared) {
            // A distinct shape, not '': a guest must not collide with a user
            // whose identifier happens to be the empty string.
            $id = $this->auth->id();
            $parts['u'] = $id === null ? ['anon'] : ['id', $id];
        }

        // SHA-256, not a fast non-cryptographic hash. The arguments are chosen
        // by the caller and the user component is what an attacker would want
        // to collide with; xxh128 makes no collision-resistance claim. The
        // cost is nanoseconds against a query that touches the database.
        return 'q:' . substr(hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR)), 0, 32);
    }

    /**
     * A model argument keys on its class and id, not on its object identity or
     * its current field values.
     *
     * @param array<string, mixed> $arguments
     */
    private static function canonical(array $arguments): string
    {
        ksort($arguments);

        $flat = [];
        foreach ($arguments as $name => $value) {
            $flat[$name] = $value instanceof Model
                ? $value::class . ':' . $value->primaryKey()
                : $value;
        }

        // No partial output. A value that cannot be encoded would otherwise
        // produce a key that two different argument sets could share, and a
        // shared key is one caller reading another's result.
        return json_encode($flat, JSON_THROW_ON_ERROR);
    }
}
