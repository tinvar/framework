<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Cache\QueryCache;
use Tinvar\Data\SharedTags;
use Tinvar\Live\Invalidator;
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
