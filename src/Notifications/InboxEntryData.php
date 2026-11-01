<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Carbon\CarbonImmutable;
use Tinvar\Data\Data;

/** One inbox entry, as the client is sent it: text to draw as text, and a path on this site. */
final readonly class InboxEntryData extends Data
{
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public string $body,
        public ?string $url,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $readAt,
    ) {}
}
