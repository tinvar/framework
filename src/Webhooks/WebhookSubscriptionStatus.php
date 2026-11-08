<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * Where a stored subscription stands (ADR 0197): sent to, paused by a
 * member, or disabled by the framework after its deliveries kept failing --
 * with the reason beside it -- until a member resumes it.
 */
enum WebhookSubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Disabled = 'disabled';

    public function describe(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Disabled => 'Disabled',
        };
    }
}
