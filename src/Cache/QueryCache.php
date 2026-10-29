<?php

declare(strict_types=1);

namespace Tinvar\Cache;

use Tinvar\Attributes\Query;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Features\FlagReads;
use Tinvar\Features\Features;
use Tinvar\Orm\Model;
use Tinvar\Orm\TenantBounds;

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
 * The feature flags a query read cannot be known before its body has run,
 * so they key a second step (ADR 0094). An answer whose body read none is
 * kept under the key itself. Once a body of the call reads one, the key
 * holds a marker naming every flag its bodies have been seen to read, and
 * each answer is kept under the key and those flags' values: a lookup reads
 * the marker, reads the flags for the caller, and looks there. Every answer
 * carries the flags its body read, and is served only to a caller for whom
 * they all read the same -- the flags a body reads are all it can have
 * depended on. One whose reads could not be told apart from another body's,
 * or that read a flag for a scope it named, is never kept.
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
     * @param string|(\Closure(): string) $locale the locale an answer is for. The locale is
     *        the application's: a string where it has one, or -- as the panel is read from
     *        its context -- a closure asked at every lookup for the locale of the request
     *        or render in hand, so one worker serving two languages never answers one
     *        from the other's entry. One that throws fails the call, never keys it loosely.
     */
    public function __construct(
        private readonly Store $store,
        private readonly AuthContext $auth,
        private readonly ?PanelContext $panels = null,
        private readonly string|\Closure $locale = 'en',
        private readonly string $build = '',
        private readonly ?Features $features = null,
    ) {}

    /**
     * Bumped whenever the key's composition changes, so old entries cannot be
     * read under a new scheme. v2 added the auth epoch, v3 the build (ADR
     * 0049), v4 the feature flags read, with the entries' new shape (ADR 0094).
     */
    private const string KEY_SCHEMA = 'v4';

    /** An entry holding an answer: the flags its body read, and the answer. */
    private const string ANSWER = 'answer';

    /** An entry naming the flags the bodies of a call have been seen to read. */
    private const string FLAGS = 'flags';

    /** Flags a call's marker names, at most: past them, its answers are not kept. */
    public const int MAX_FLAGS = 16;

    /**
     * What each key's lookup did, from its miss to the put that answers it:
     * the flags the marker named, and the variant key it looked under, or
     * null where there was no marker. A put writes a variant only under the
     * key its lookup missed on -- the store's own miss, which it holds a put
     * to (Misses), so an answer read before a forget that landed after it is
     * refused -- and never carries an answer it did not compute. Bounded,
     * since a body that fails puts nothing.
     *
     * @var array<string, array{flags: list<string>, variant: string|null}>
     */
    private array $looked = [];

    private const int MAX_LOOKED = 1024;

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
        $miss = ['hit' => false, 'value' => null];
        if (!$this->enabledFor($declaration)) {
            return $miss;
        }

        // Unkeyable means uncacheable, never loosely keyed.
        $key = $this->keyOrNull($name, $declaration, $arguments, $tenant);
        if ($key === null) {
            return $miss;
        }

        if (\count($this->looked) >= self::MAX_LOOKED) {
            $this->looked = [];
        }
        $this->looked[$key] = ['flags' => [], 'variant' => null];

        $found = $this->store->get($key);
        if (!$found['hit']) {
            return $miss;
        }

        try {
            $entry = $found['value'];
            $flags = self::flagsNamed($entry);
            if ($flags !== null) {
                $values = $this->currentValues($flags);
                if ($values === null) {
                    return $miss;
                }
                $variant = self::variantKey($key, $values);
                $this->looked[$key] = ['flags' => $flags, 'variant' => $variant];
                $found = $this->store->get($variant);
                if (!$found['hit']) {
                    return $miss;
                }
                $entry = $found['value'];
            }

            // However it was found, an answer is served only to a caller
            // whose flags read as its body's did.
            $answer = self::answer($entry);
            if ($answer === null || $this->currentValues(array_keys($answer['read'])) !== $answer['read']) {
                return $miss;
            }
        } catch (\Throwable $e) {
            // A flag store that cannot answer leaves the answer unproven, and
            // the body runs -- to fail the same way, or to answer.
            $this->failures[] = $e;

            return $miss;
        }

        unset($this->looked[$key]);

        return ['hit' => true, 'value' => $answer['value']];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param FlagReads|null $reads what the body read, as the runner recorded
     *        it; null says it read no flag
     */
    public function put(
        string $name,
        Query $declaration,
        array $arguments,
        string $tenant,
        mixed $value,
        ?FlagReads $reads = null,
    ): void {
        if (!$this->enabledFor($declaration)) {
            return;
        }

        // A read that could not be told from another body's, a flag read
        // for a scope the body named, or a flag that changed while the body
        // ran: no set of the caller's values describes this answer.
        if ($reads?->tainted() === true) {
            return;
        }
        $read = $reads?->flags() ?? [];

        $key = $this->keyOrNull($name, $declaration, $arguments, $tenant);
        if ($key === null) {
            return;
        }
        $looked = $this->looked[$key] ?? ['flags' => [], 'variant' => null];
        unset($this->looked[$key]);

        try {
            $ttl = Duration::seconds((string) $declaration->cache);
            // Filed as the key is: an entry keyed apart for a request granted
            // the subtree is tagged under the subtree's namespace, which a
            // write made as any tenant in it reaches (TagNamespace).
            $tags = $this->tagsFor($declaration, $arguments, $tenant, $this->subtreeGranted());
            $answer = ['k' => self::ANSWER, 'r' => $read, 'v' => $value];

            // No flag read, where no body of this call has been seen to read
            // one: kept under the key itself, one lookup as before flags.
            if ($read === [] && $looked['variant'] === null) {
                $this->store->put($key, $answer, $ttl, $tags);

                return;
            }

            // Otherwise under the key and the values of every flag a body of
            // this call has been seen to read, with a marker at the key
            // naming them. The marker is a list of names, read by no one as
            // an answer, so it outlives the forgets its answers do not.
            $flags = array_values(array_unique([...$looked['flags'], ...array_keys($read)]));
            sort($flags, SORT_STRING);
            $values = \count($flags) > self::MAX_FLAGS ? null : $this->currentValues($flags, $read);
            if ($values === null) {
                return;
            }
            if ($flags !== $looked['flags']) {
                $this->store->put($key, ['k' => self::FLAGS, 'f' => $flags], $ttl);
            }

            // The answer only under the key its lookup missed on: one it
            // never looked under has no miss for the store to hold the put
            // to, so a forget between the two would go unseen. The next
            // lookup finds the marker, misses there, and keeps it.
            $variant = self::variantKey($key, $values);
            if ($variant === $looked['variant']) {
                $this->store->put($variant, $answer, $ttl, $tags);
            }
        } catch (\Throwable $e) {
            // A mistyped tag closure or TTL is a declaration bug, and it is
            // only reachable on a miss -- after the body has run. Losing every
            // other result in the tick over it would be the wrong trade; not
            // caching this one is the right one. A flag store that cannot
            // answer leaves the same choice.
            $this->failures[] = $e;
        }
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
     * @param TenantBounds|null $tenants the tenant tree, as the Orm reads it; null for tenants with none
     * @return int entries invalidated
     */
    public function forgetTags(array $tags, string $tenant, ?TenantBounds $tenants): int
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
            'l' => $this->locale instanceof \Closure ? ($this->locale)() : $this->locale,
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
     * Each flag's value for the caller, sorted by name -- $known's where the
     * body already read it -- or null when one is not declared, or there is
     * no Features to read it with: an answer keyed by a flag this cannot
     * read is one this cannot prove is the caller's.
     *
     * @param list<array-key> $flags
     * @param array<string, bool> $known
     * @return array<string, bool>|null
     */
    private function currentValues(array $flags, array $known = []): ?array
    {
        if ($flags === []) {
            return [];
        }
        if ($this->features === null) {
            return null;
        }

        $values = [];
        foreach ($flags as $flag) {
            $flag = (string) $flag;
            if (!$this->features->declares($flag)) {
                return null;
            }
            $values[$flag] = $known[$flag] ?? $this->features->active($flag);
        }
        ksort($values, SORT_STRING);

        return $values;
    }

    /** @param array<string, bool> $values */
    private static function variantKey(string $key, array $values): string
    {
        return 'qf:' . substr(hash('sha256', $key . "\0" . json_encode($values, JSON_THROW_ON_ERROR)), 0, 32);
    }

    /**
     * The flags a marker names, or null for any other entry. Read strictly,
     * as answer() reads: a store shared between workers is a store someone
     * else could have written.
     *
     * @return list<string>|null
     */
    private static function flagsNamed(mixed $entry): ?array
    {
        if (!\is_array($entry) || \count($entry) !== 2 || ($entry['k'] ?? null) !== self::FLAGS
            || !\is_array($entry['f'] ?? null) || !array_is_list($entry['f'])
            || $entry['f'] === [] || \count($entry['f']) > self::MAX_FLAGS) {
            return null;
        }

        $flags = [];
        foreach ($entry['f'] as $flag) {
            if (!\is_string($flag)) {
                return null;
            }
            $flags[] = $flag;
        }

        return $flags;
    }

    /**
     * An answer's flags read and value, or null for any other entry.
     *
     * @return array{read: array<string, bool>, value: mixed}|null
     */
    private static function answer(mixed $entry): ?array
    {
        if (!\is_array($entry) || \count($entry) !== 3 || ($entry['k'] ?? null) !== self::ANSWER
            || !\is_array($entry['r'] ?? null) || !\array_key_exists('v', $entry)) {
            return null;
        }

        $read = [];
        foreach ($entry['r'] as $flag => $active) {
            if (!\is_string($flag) || !\is_bool($active)) {
                return null;
            }
            $read[$flag] = $active;
        }
        ksort($read, SORT_STRING);

        return ['read' => $read, 'value' => $entry['v']];
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
