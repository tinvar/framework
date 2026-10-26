<?php

declare(strict_types=1);

namespace Tinvar\Cache;

use Tinvar\Attributes\Query;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Orm\Model;
use Tinvar\Orm\TenantHierarchy;

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
 *
 * Two departures (ADR 0049). Beside the user, the key names the user's auth
 * epoch, so nothing computed for them before the application last moved it
 * -- a role taken away, impersonation stopped, every session signed out --
 * is served to them after. And it names the build: an entry is read only by
 * the build that cached it, so a store that outlives a deploy, or is shared
 * by servers on two builds during one, never hands a build the shape
 * another one's code returned.
 */
final class QueryCache
{
    /** @var list<\Throwable> */
    private array $failures = [];

    /**
     * The panel comes from a CONTEXT, not a constructor argument.
     *
     * "The panel and effective scope are part of query cache keys, fragment
     * and route tokens" -- which makes the panel per request. Baking it in at
     * construction was wrong the moment one worker served two panels: every
     * entry would be keyed with whichever panel the instance happened to be
     * built for, so a narrow panel could be served a wide panel's answer.
     *
     * A null context means an application with no panels at all, which keys
     * as the empty panel rather than guessing a name.
     *
     * @param string $build the build this code is (BuildRegistry::$current). Give it
     *        with any store that outlives a worker -- ApcuStore, PdoStore -- where
     *        entries cached by the build before a deploy, or by servers still on it
     *        during one, would otherwise be read: what a query returns is its
     *        build's, and the next build may return another shape. '' is one build
     *        for every entry, which only a store as short-lived as the code is can be.
     */
    public function __construct(
        private readonly Store $store,
        private readonly AuthContext $auth,
        private readonly ?PanelContext $panels = null,
        private readonly string $locale = 'en',
        private readonly string $build = '',
    ) {}

    /**
     * Bumped whenever the key's composition changes, so old entries cannot be
     * read under a new scheme. v2 added the auth epoch, v3 the build (ADR
     * 0049); adding the feature-flag component will need it again.
     */
    private const string KEY_SCHEMA = 'v3';

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
            // Filed as the key is: an entry keyed apart for a request granted
            // the subtree is tagged under the subtree's namespace, which a
            // write made as any tenant in it reaches (TagNamespace).
            $tags = $this->tagsFor($declaration, $arguments, $tenant, $this->subtreeGranted());
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
     * Nor has $tenants, for the same reason. An entry a request granted the
     * subtree put is filed under the subtree's namespace, so a write made as
     * a tenant below reaches it only by walking up the tree -- the one reads
     * across a subtree are made with, Orm::tenants(). Defaulted to none, a
     * child's write would leave its parent's pages stale for their TTL.
     *
     * @param list<string> $tags
     * @param TenantHierarchy|null $tenants the tenant tree; null for tenants with none
     * @return int entries invalidated
     */
    public function forgetTags(array $tags, string $tenant, ?TenantHierarchy $tenants): int
    {
        return $this->store->forgetTags(TagNamespace::written($tags, $tenant, $tenants));
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
    public function tagsFor(Query $declaration, array $arguments, string $tenant = '', bool $subtree = false): array
    {
        return self::tagsOf($declaration, $arguments, $tenant, $subtree);
    }

    /**
     * The same, with no cache to ask: a query's tags say what it read
     * whether or not its answer is kept. A render's live subscription is
     * scoped to them (Tinvar\Live), and an application that caches nothing
     * still wants its pages told when what they read changes.
     *
     * @param array<string, mixed> $arguments
     * @param bool $subtree whether a membership granted the read its tenant's
     *        subtree, which files the tags under the subtree's namespace
     * @return list<string>
     */
    public static function tagsOf(Query $declaration, array $arguments, string $tenant = '', bool $subtree = false): array
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
                    'A #[Query(tags:)] closure must return strings, got ' . get_debug_type($tag) . '.',
                );
            }
            $strings[] = $tag;
        }

        // Namespaced by tenant, as the key is. A flat namespace lets a write
        // in one tenant drop another's entries -- not a disclosure, but a
        // cross-tenant side channel and a cheap way to defeat the cache for
        // everyone.
        return TagNamespace::read($strings, $tenant, $subtree);
    }

    /**
     * Whether the request was granted reads across its tenant's subtree:
     * what keys an entry apart (key()) and files its tags (put()).
     */
    private function subtreeGranted(): bool
    {
        return $this->panels?->granted()?->subtree === true;
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
        // The build, beside what the spec lists: the same call answered by
        // another build's code may have another shape (ADR 0049).
        $parts = [
            'v' => self::KEY_SCHEMA,
            'b' => $this->build,
            'q' => $name,
            'a' => self::canonical($arguments),
            't' => $tenant,
            'p' => $this->panels?->currentOrNull() ?? '',
            'l' => $this->locale,
        ];

        // "The panel and its effective scope": a request whose membership
        // granted reads across its tenant's subtree may get a different
        // answer from the same query, so it keys apart. Only then, so every
        // other key is the one it was.
        if ($this->subtreeGranted()) {
            $parts['s'] = 'subtree';
        }

        // "shared: true drops the user from the key." Safe only when the body
        // cannot read the current user, which BuildRules checks -- by type
        // closure for injected dependencies, textually for direct calls, and
        // not at all through the wider call graph. Its docblock says so. The
        // epoch goes with the user: an answer that cannot depend on who asks
        // cannot depend on what they were allowed when they asked.
        if (!$declaration->shared) {
            // A distinct shape, not '': a guest must not collide with a user
            // whose identifier happens to be the empty string.
            //
            // With the user, their auth epoch (ADR 0049). The gate runs before
            // every lookup, but it answers only whether the user may make the
            // call; what a body that reads the user returned -- the rows their
            // role reached, the fields an impersonating admin was shown -- is
            // not asked again on a hit. Keyed by the user alone, that answer
            // outlived the role change or the impersonation that shaped it
            // until its TTL ran out. The application moves the epoch at those
            // moments, so the next lookup misses and the body runs as who the
            // user is now. Hex, because the epoch is the application's string
            // and may be any bytes: bytes JSON cannot encode would have left
            // the user uncacheable rather than keyed. A guest has no epoch
            // (AuthContext keeps it ''), so the guest key is the one shape it
            // was.
            $id = $this->auth->id();
            $parts['u'] = $id === null ? ['anon'] : ['id', $id, bin2hex($this->auth->epoch())];
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
