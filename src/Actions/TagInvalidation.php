<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Cache\QueryCache;
use Tinvar\Data\SharedTags;
use Tinvar\Live\Invalidator;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Render\StaticPages;

/**
 * What a write that names tags does with them on the server: everything an
 * action's revalidated tags have always done, for every kind of entry point
 * that writes (ADR 0075).
 *
 * An action's result named its tags and ActionRunner applied them. An
 * unsafe #[Post] or #[Endpoint] and an #[Import] wrote just as much and
 * applied nothing: a cached query kept the old answer for its TTL, no page
 * listening was told, a once-shared hold stayed believed and a static page
 * kept serving. They declare their tags now (`revalidates:`), and this is
 * what each of them, and an action, runs:
 *
 *   - the query cache forgets them, at once and again after the commit
 *     (forgetCached());
 *   - every client listening for them is told, once the writes commit
 *     (Invalidator);
 *   - every once-shared hold bound to them stops being believed, at once
 *     -- a failure fails the write -- and again after the commit
 *     (SharedTags);
 *   - the static pages showing them are taken out of service after the
 *     commit, outside a tenant.
 *
 * And invalidate(), for the writes no declaration covers: a job's, a
 * scheduled task's or a command's, which the container hands this to.
 *
 * Each in every tenant the write ran as, the one it began in first, so a
 * write made as a child reaches its parents' reads across the subtree.
 */
final readonly class TagInvalidation
{
    /** A placeholder in a declared tag: `{name}`, a PHP parameter's name. */
    private const string PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    public function __construct(
        private Orm $orm,
        private ?QueryCache $cache = null,
        // Tells every other client listening that the tags were invalidated,
        // once the writes are committed (Tinvar\Live).
        private ?Invalidator $live = null,
        // Takes the static pages showing what the tags name out of service,
        // once the writes are committed.
        private ?StaticPages $static = null,
        // Moves the versions once-shared holds are bound to, so a hold on a
        // value the write changed is believed by no browser, not only the
        // one its response reaches (ADR 0074).
        private ?SharedTags $sharedTags = null,
    ) {}

    /**
     * Refuses tags a declaration cannot mean: an empty one, which names
     * nothing, and one holding a NUL, the byte a tag's tenant is kept apart
     * from it by (TagNamespace).
     *
     * @param array<mixed> $tags
     * @return list<string>
     */
    public static function declared(string $where, array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            if (!\is_string($tag) || $tag === '' || str_contains($tag, "\0")) {
                throw new \InvalidArgumentException("{$where} revalidates a tag that is not one: each is a non-empty string with no NUL.");
            }
            $out[] = $tag;
        }

        return array_values(array_unique($out));
    }

    /**
     * The `{name}` placeholders a declared tag holds, each once: what an
     * endpoint's path parameter or an import's argument fills, so a write
     * names the one record it changed -- `rack:{rack}` -- as an action's
     * result does (ADR 0106).
     *
     * @return list<string>
     */
    public static function placeholders(string $tag): array
    {
        preg_match_all(self::PLACEHOLDER, $tag, $found);

        return array_values(array_unique($found[1]));
    }

    /**
     * Refuses a declaration whose tags name a placeholder $known does not:
     * a typo would otherwise fill nothing and invalidate a tag no read
     * carries, the first time anyone wrote.
     *
     * @param list<string> $declared
     * @param list<string> $known the names a placeholder may take
     * @throws \InvalidArgumentException naming the first unknown one
     */
    public static function assertPlaceholders(string $where, array $declared, array $known): void
    {
        foreach ($declared as $tag) {
            foreach (self::placeholders($tag) as $name) {
                if (!\in_array($name, $known, true)) {
                    throw new \InvalidArgumentException(
                        "{$where} revalidates '{$tag}', and has no " . ($known === [] ? 'parameter' : 'parameter named') . " '{$name}' to fill it: "
                        . ($known === [] ? 'it has none.' : 'it has ' . implode(', ', $known) . '.'),
                    );
                }
            }
        }
    }

    /**
     * The declared tags with each placeholder filled from $values: a model
     * by its key, a backed enum by its value, a scalar as text; nothing for
     * none. A tag with no placeholder is as declared.
     *
     * A value is what a caller sent -- a path segment, an argument -- so it
     * is refused where it would carry what no tag may: a control character,
     * which a header line or a tag's namespace could be split on, or the
     * comma X-Invalidated-Tags lists tags apart with. Asked before the write
     * it describes, so a refusal changes nothing.
     *
     * @param list<string> $declared
     * @param array<string, mixed> $values by placeholder name
     * @return list<string>
     * @throws \InvalidArgumentException for a value no tag may carry
     */
    public static function filled(array $declared, array $values): array
    {
        $out = [];
        foreach ($declared as $tag) {
            $out[] = preg_replace_callback(self::PLACEHOLDER, static function (array $match) use ($values): string {
                $value = self::text($values[$match[1]] ?? null);
                if (preg_match('/[\x00-\x1f\x7f,]/', $value) === 1) {
                    throw new \InvalidArgumentException("The value of '{$match[1]}' cannot be carried by a tag.");
                }

                return $value;
            }, $tag) ?? $tag;
        }

        return array_values(array_unique($out));
    }

    /** A placeholder's value as a tag spells it. */
    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof Model => (string) Metadata::for($value::class)->keyOf($value),
            $value instanceof \BackedEnum => (string) $value->value,
            \is_bool($value) => $value ? '1' : '0',
            \is_scalar($value) => (string) $value,
            default => throw new \InvalidArgumentException('A tag is filled from a model, an enum or a scalar, and was given ' . get_debug_type($value) . '.'),
        };
    }

    /**
     * Invalidates the tags as written in $tenant -- the tenant the ORM runs
     * as unless given -- everything an action's would do: for a job, a
     * scheduled task or a command that wrote what a page shows (ADR 0106).
     * A job runs in the tenant it was dispatched in, so a job's write
     * reaches that tenant's pages, and a static page only from outside any.
     *
     * @param list<string> $tags as the application names them
     */
    public function invalidate(array $tags, ?string $tenant = null): void
    {
        $this->invalidateIn(array_values(array_unique(array_filter($tags, static fn (string $tag): bool => $tag !== ''))), [$tenant ?? $this->orm->tenant()]);
    }

    /**
     * Invalidates the tags, in each of the tenants a write ran as.
     *
     * @param list<string> $tags as the application names them
     * @param list<string> $tenants every tenant the write ran as, the one it began in first
     */
    public function invalidateIn(array $tags, array $tenants): void
    {
        if ($tags === []) {
            return;
        }

        if ($this->cache !== null) {
            $this->forgetCached($this->cache, $tags, $tenants);
        }

        $this->live?->invalidateIn($tags, $tenants);

        $this->sharedTags?->invalidateIn($tags, $tenants);

        // Only where the write ran as no tenant, and after the commit
        // (StaticPages::invalidateAfterCommit()).
        $this->static?->invalidateAfterCommit($this->orm, $tags, $tenants);
    }

    /**
     * Forgets the tags in the query cache: at once, and -- when the write
     * ran inside a transaction -- again after the outermost one commits.
     *
     * At once, for what reads inside the transaction and sees its writes: a
     * re-render made there must not be handed an entry from before them.
     * After the commit, because a store shared between workers (ApcuStore,
     * PdoStore) is read by requests the transaction does not cover: one can
     * miss between the forget and the commit, read the rows as they were --
     * the writes are not visible to it yet -- and keep that answer for its
     * whole TTL. The second forget drops it. With no transaction open the
     * writes are in already, and the one forget is enough. A rollback
     * forgets nothing more: nothing changed.
     *
     * A failure at once is the write's, as it always was. After a commit it
     * is logged, as a static page that could not be taken down is: thrown
     * there, it would make transaction() throw for writes that are in, and
     * a caller that retries would write them twice.
     *
     * @param list<string> $tags
     * @param list<string> $tenants
     */
    private function forgetCached(QueryCache $cache, array $tags, array $tenants): void
    {
        $tree = $this->orm->tenants();
        $forget = static function () use ($cache, $tags, $tenants, $tree): void {
            foreach ($tenants as $tenant) {
                $cache->forgetTags($tags, $tenant, $tree);
            }
        };

        $forget();

        if ($this->orm->connection()->inTransaction()) {
            $this->orm->afterCommit(static function () use ($forget): void {
                try {
                    $forget();
                } catch (\Throwable $e) {
                    error_log('[tinvar] cached queries could not be invalidated after a commit: ' . $e->getMessage());
                }
            });
        }
    }
}
