<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Cache\TagNamespace;
use Tinvar\Cache\TagVersions;
use Tinvar\Orm\Orm;

/**
 * The server's side of a once-shared value's tags (ADR 0074): what a hold
 * is bound to, and how a write that no response announces stops it being
 * believed.
 *
 * `X-Invalidated-Tags` reaches the browser whose action wrote. Another
 * browser holding the same value -- another tab's session, the customer's
 * phone -- and a write made by a job, which answers no browser at all,
 * would leave a hold believed until its `until`. So a hold also carries a
 * stamp: the versions, in TagVersions, of its key's tags in the tenant it
 * was minted in. invalidate() bumps them; the stamp the hold carries is no
 * longer the stamp the tags have, and the hold proves nothing.
 *
 * Bound to the tenant's own namespace and its subtree's (TagNamespace): a
 * write as the tenant moves the first, and one as any tenant below it the
 * second, so a value read across a subtree is dropped by a write anywhere
 * in it, and one tenant's write never touches another's holds.
 *
 * Fail closed throughout. A stamp that cannot be read is no stamp: no hold
 * is minted, and none is believed. A bump that cannot be made throws, and
 * the write that asked for it fails with it.
 */
final readonly class SharedTags
{
    /** @param (\Closure(\Throwable): mixed)|null $report where a stamp that could not be read, or a bump that failed after a commit, is logged */
    public function __construct(
        private TagVersions $versions,
        private Orm $orm,
        private ?\Closure $report = null,
    ) {}

    /**
     * What a hold for a key carrying $tags, in $tenant, is bound to now: a
     * digest of its tags' versions; '' for a key with no tags; null when
     * the store cannot say.
     *
     * @param list<string> $tags as the application names them
     */
    public function stamp(array $tags, string $tenant): ?string
    {
        if ($tags === []) {
            return '';
        }

        $namespaced = TagNamespace::read($tags, $tenant, false);
        if ($tenant !== '') {
            array_push($namespaced, ...TagNamespace::read($tags, $tenant, true));
        }

        try {
            $versions = $this->versions->versions($namespaced);
        } catch (\Throwable $e) {
            $this->report($e);

            return null;
        }

        $parts = [];
        foreach ($namespaced as $tag) {
            if (!\array_key_exists($tag, $versions)) {
                // A store that left a tag out has said nothing about it.
                return null;
            }
            $parts[] = [$tag, $versions[$tag]];
        }

        return rtrim(strtr(base64_encode(hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR), true)), '+/', '-_'), '=');
    }

    /**
     * Stops every hold bound to these tags being believed: a write the
     * holders were not told of -- a job shipping an order, a write in
     * another browser.
     *
     * @param list<string> $tags as the application names them
     * @param string|null $tenant whose they are; the tenant the ORM runs as unless given
     */
    public function invalidate(array $tags, ?string $tenant = null): void
    {
        $this->invalidateIn($tags, [$tenant ?? $this->orm->tenant()]);
    }

    /**
     * invalidate(), in each of several tenants: an action that wrote as a
     * child as well as its own.
     *
     * Now, and again once the writes commit. Now, so a failure fails the
     * write -- inside its transaction, it rolls back -- rather than leave a
     * hold believed over a write that stood. Again after the commit,
     * because a page drawn between the two read the rows as they were and
     * was handed a hold at the new versions; the second bump stops that one
     * being believed. A failure then is logged: the writes are in, and the
     * one bump already made has stopped every hold from before them.
     *
     * @param list<string> $tags as the application names them
     * @param list<string> $tenants whose they are
     */
    public function invalidateIn(array $tags, array $tenants): void
    {
        $tags = array_values(array_unique(array_filter($tags, static fn (string $tag): bool => $tag !== '')));
        if ($tags === []) {
            return;
        }

        $tree = $this->orm->tenants();
        $namespaced = [];
        foreach (array_unique($tenants) as $tenant) {
            array_push($namespaced, ...TagNamespace::written($tags, $tenant, $tree));
        }
        $namespaced = array_values(array_unique($namespaced));

        $this->versions->bump($namespaced);

        if ($this->orm->connection()->inTransaction()) {
            $versions = $this->versions;
            $this->orm->afterCommit(function () use ($versions, $namespaced): void {
                try {
                    $versions->bump($namespaced);
                } catch (\Throwable $e) {
                    $this->report($e);
                }
            });
        }
    }

    private function report(\Throwable $e): void
    {
        if ($this->report !== null) {
            ($this->report)($e);

            return;
        }

        error_log('[tinvar] the tag versions once-shared holds are bound to could not be read or moved: ' . $e->getMessage());
    }
}
