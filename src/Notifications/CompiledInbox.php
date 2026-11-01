<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

/** One #[Inbox] class, reflected once. */
final readonly class CompiledInbox
{
    /** The prefix of an inbox's personal channel: `inbox.customer`. */
    public const string CHANNEL_PREFIX = 'inbox.';

    /**
     * @param class-string $class
     * @param class-string|null $tenantFrom what its #[TenantFrom] names
     * @param list<\Tinvar\Attributes\Panel> $panels
     * @param list<class-string> $middleware
     */
    public function __construct(
        public string $name,
        public string $class,
        public ?string $tenantFrom,
        public array $panels,
        public array $middleware,
        public ?string $host = null,
    ) {}

    /** The personal channel its live updates are heard on. */
    public function channel(): string
    {
        return self::CHANNEL_PREFIX . $this->name;
    }
}
