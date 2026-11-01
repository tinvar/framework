<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Carbon\CarbonImmutable;

/**
 * One entry in a user's inbox, as the store keeps it: whose it is -- the
 * tenant claim it was written in and the user -- what kind of
 * notification wrote it, what it says, and whether it has been read.
 *
 * Its id is a UUIDv7, so the feed is newest first by id alone, and a page
 * goes on from the last id it showed.
 */
final readonly class InboxEntry
{
    public function __construct(
        public string $id,
        public string $tenant,
        public string $user,
        public string $type,
        public InboxMessage $message,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $readAt = null,
    ) {}

    public function data(): InboxEntryData
    {
        return new InboxEntryData(
            id: $this->id,
            type: $this->type,
            title: $this->message->title,
            body: $this->message->body,
            url: $this->message->url,
            createdAt: $this->createdAt,
            readAt: $this->readAt,
        );
    }
}
