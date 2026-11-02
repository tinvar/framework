<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Carbon\CarbonImmutable;
use Tinvar\Channels\Channels;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;
use Tinvar\Support\Uuid;

/**
 * A user's inbox in the tenant in force (ADR 0152): its entries, read and
 * written through the store, and every change announced on each inbox's
 * personal channel, so every page the user has open hears it.
 *
 * The tenant is never a caller's to name: it is the claim the Orm runs as
 * now -- the request's, once its #[TenantFrom] has run, or the job's, as
 * its seal entered it -- as for every other token, row and topic. An
 * announcement goes once the write commits, and nowhere without a hub.
 */
final readonly class InboxFeed
{
    /** Entries a page holds unless asked for fewer. */
    public const int PAGE = 20;

    public const int MAX_PAGE = 50;

    /**
     * @param list<string> $channels the personal channel of each #[Inbox] (InboxRegistry::channels())
     */
    public function __construct(
        private InboxStore $store,
        private Orm $orm,
        private ?Channels $live = null,
        private array $channels = [],
    ) {}

    /** Writes $message to $user's inbox, as notification $type, and tells their open pages. */
    public function add(string $user, string $type, InboxMessage $message): InboxEntry
    {
        $tenant = $this->tenant();
        $entry = new InboxEntry(Uuid::v7(), $tenant, $user, $type, $message, CarbonImmutable::now('UTC'));
        $this->store->add($entry);
        $this->announce($user, new InboxUpdateData($this->store->unread($tenant, $user), $entry->data()));

        return $entry;
    }

    /** A page of $user's inbox, newest first, going on from $before when it is given. */
    public function page(string $user, int $limit = self::PAGE, ?string $before = null): InboxPageData
    {
        $tenant = $this->tenant();
        $limit = max(1, min(self::MAX_PAGE, $limit));
        $entries = $this->store->page($tenant, $user, $limit + 1, $before);
        $more = \count($entries) > $limit;
        $entries = \array_slice($entries, 0, $limit);

        return new InboxPageData(
            entries: array_map(static fn (InboxEntry $entry): InboxEntryData => $entry->data(), $entries),
            unread: $this->store->unread($tenant, $user),
            next: $more && $entries !== [] ? $entries[\count($entries) - 1]->id : null,
        );
    }

    public function unread(string $user): int
    {
        return $this->store->unread($this->tenant(), $user);
    }

    /**
     * Marks $user's entries read -- those $ids names, or every one for
     * null -- and tells their other pages; how many it marked.
     *
     * @param list<string>|null $ids
     */
    public function markRead(string $user, ?array $ids): int
    {
        $tenant = $this->tenant();
        $marked = $this->store->markRead($tenant, $user, $ids, CarbonImmutable::now('UTC'));
        if ($marked > 0) {
            $this->announce($user, new InboxUpdateData($this->store->unread($tenant, $user), null, $ids ?? [], $ids === null));
        }

        return $marked;
    }

    private function tenant(): string
    {
        if ($this->orm->isWidened()) {
            throw new \LogicException('An inbox is read and written in one tenant: not inside acrossTenants() or withinSubtree(). Use actAs().');
        }

        return TenantClaim::current($this->orm);
    }

    private function announce(string $user, InboxUpdateData $update): void
    {
        foreach ($this->channels as $channel) {
            $this->live?->publishTo($user, $channel, $update);
        }
    }
}
