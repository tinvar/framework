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
    public function __construct(
        private readonly Store $store,
        private readonly AuthContext $auth,
        private readonly string $panel = 'default',
        private readonly string $locale = 'en',
    ) {}

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

        $this->store->put(
            $key,
            $value,
            Duration::seconds((string) $declaration->cache),
            $this->tagsFor($declaration, $arguments),
        );
    }

    /** @param list<string> $tags @return int entries invalidated */
    public function forgetTags(array $tags): int
    {
        return $this->store->forgetTags($tags);
    }

    /**
     * Tags come from the attribute's closure, which receives the query's
     * arguments. A model argument is passed as the model, so a tag can read
     * its id without the closure doing a lookup.
     *
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    public function tagsFor(Query $declaration, array $arguments): array
    {
        if ($declaration->tags === null) {
            return [];
        }

        $tags = ($declaration->tags)(...$arguments);

        if (!\is_array($tags)) {
            throw new \LogicException('A #[Query(tags:)] closure must return a list of strings.');
        }

        return array_values(array_map(strval(...), $tags));
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
            'q' => $name,
            'a' => self::canonical($arguments),
            't' => $tenant,
            'p' => $this->panel,
            'l' => $this->locale,
        ];

        // "shared: true drops the user from the key." Only safe when the body
        // cannot read the current user, which the build rule enforces.
        if (!$declaration->shared) {
            $parts['u'] = $this->auth->id() ?? '';
        }

        return 'q:' . hash('xxh128', json_encode($parts, JSON_THROW_ON_ERROR));
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
