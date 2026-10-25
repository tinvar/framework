<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Cache\TagNamespace;
use Tinvar\Orm\Orm;

/**
 * Tells the clients listening that tags were invalidated -- once the writes
 * that invalidated them are committed.
 *
 * ActionRunner calls it for every tag an action's result names
 * (revalidateTags(), withTags(): the same tags its response lists in
 * X-Invalidated-Tags), and a job calls it for the tags it changes.
 *
 * After the commit, never before (Orm::afterCommit). A client told first
 * asks for its page at once, reads the data as it was, and is not told
 * again: the update it heard is spent and what it shows stays wrong. A
 * client told of a write that is then rolled back refetches for nothing,
 * which is only waste; the first is the reason.
 *
 * Each tag goes to its own topic, namespaced by tenant (Topics), as an
 * update of its own: one update to several topics would reach a subscriber
 * of any of them carrying all of their tags, and a page allowed to hear of
 * `orders` would learn which customer's orders changed.
 */
final readonly class Invalidator
{
    /** @param (\Closure(\Throwable, ?string): string)|null $report where a publish that failed is logged */
    public function __construct(
        private Hub $hub,
        private Topics $topics,
        private Orm $orm,
        private ?\Closure $report = null,
    ) {}

    /**
     * @param list<string> $tags as the application names them
     * @param string|null $tenant whose they are; the tenant the ORM runs as
     *        unless given -- the namespace QueryCache keeps them under
     */
    public function invalidate(array $tags, ?string $tenant = null): void
    {
        $tenant ??= $this->orm->tenant();

        $updates = [];
        foreach ($tags as $tag) {
            if ($tag === '') {
                continue;
            }
            // In every namespace the write reaches: the tenant's own, and the
            // subtree's of it and of each tenant above it, where the pages
            // reading across a subtree the tenant is in listen (TagNamespace).
            foreach (TagNamespace::written([$tag], $tenant, $this->orm->tenants()) as $namespaced) {
                $topic = $this->topics->of($namespaced);
                // The topic, so the client knows which of its subscriptions
                // this is for -- an update carries no topic of its own -- and
                // the tag, so it can forget what it keeps under it, as it
                // would on reading X-Invalidated-Tags. Delivered only to
                // subscribers allowed the topic, which only a render that
                // read the tag gets.
                $updates[$topic] = json_encode(['topic' => $topic, 'tag' => $tag], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }

        if ($updates === []) {
            return;
        }

        $this->orm->afterCommit(function () use ($updates): void {
            foreach ($updates as $topic => $data) {
                try {
                    $this->hub->publish($topic, $data);
                } catch (\Throwable $e) {
                    // The write is committed and its response is owed. A page
                    // left unrefreshed is the cost of a hub that is down, and
                    // the log is where it is noticed -- not a failed action
                    // the client would retry, writing it twice.
                    $this->report($e);
                }
            }
        });
    }

    private function report(\Throwable $e): void
    {
        if ($this->report !== null) {
            ($this->report)($e, null);

            return;
        }

        error_log('[tinvar] a live invalidation could not be published: ' . $e->getMessage());
    }
}
