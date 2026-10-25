<?php

declare(strict_types=1);

namespace Tinvar\Cache;

use Tinvar\Tenancy\TenantHierarchy;

/**
 * Where a tag is kept: the namespace a read files it under, and every
 * namespace a write reaches.
 *
 * Tags are namespaced by tenant, as cache keys are -- the same tag in two
 * tenants is two tags, so a write in one never drops or announces anything
 * in the other. One place decides the shape, because three things have to
 * agree on it: the query cache's entries (QueryCache), the tags a render
 * read (QueryRunner, TagsRead), and the live topics both are published and
 * subscribed under (Topics, Invalidator).
 *
 * A read made where a membership granted the tenant's subtree (ADR 0047)
 * is filed under the subtree's namespace, not the tenant's own: it may
 * show the rows of every tenant below, and a write made as any of them
 * must reach it. So a write reaches its tenant's own namespace and the
 * subtree namespace of that tenant and of each one above it -- the pages
 * reading across a subtree it is in, and no other tenant's. A child's
 * write drops none of its parent's own reads, only the parent's reads of
 * the subtree the child's rows are part of.
 */
final class TagNamespace
{
    /**
     * $tags as a read in $tenant files them.
     *
     * @param list<string> $tags as the application names them
     * @param bool $subtree whether a membership granted the read the tenant's subtree
     * @return list<string>
     */
    public static function read(array $tags, string $tenant, bool $subtree): array
    {
        return self::under($tags, self::of($tenant, $subtree && $tenant !== ''));
    }

    /**
     * $tags in every namespace a write made as $tenant reaches: its own, and
     * -- given the tree -- the subtree's of it and of every tenant above it.
     *
     * The tree is the one reads across a subtree are made with; without one
     * nothing reads across a subtree, and the tenant's own is all there is.
     * Walked up with TenantHierarchy's limits, so a tree that loops reaches
     * the tenants above as far as the loop and no further.
     *
     * @param list<string> $tags as the application names them
     * @return list<string> namespace by namespace, the tenant's own first, each
     *         holding $tags in the order given
     */
    public static function written(array $tags, string $tenant, ?TenantHierarchy $tenants): array
    {
        $namespaced = self::under($tags, self::of($tenant, false));
        if ($tenants === null || $tenant === '') {
            return $namespaced;
        }

        foreach ([$tenant, ...$tenants->ancestors($tenant)] as $reaching) {
            array_push($namespaced, ...self::under($tags, self::of($reaching, true)));
        }

        return $namespaced;
    }

    /**
     * The tenant's own namespace, or its subtree's.
     *
     * NUL separates them as it separates the tag: a tenant's own tag that
     * spelled out "subtree\0..." would share a namespace only with its own
     * tenant's reads, never another tenant's.
     */
    private static function of(string $tenant, bool $subtree): string
    {
        return $subtree ? $tenant . "\0subtree" : $tenant;
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private static function under(array $tags, string $namespace): array
    {
        return array_map(static fn (string $tag): string => $namespace . "\0" . $tag, $tags);
    }
}
