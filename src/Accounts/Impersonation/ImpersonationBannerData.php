<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

use Carbon\CarbonImmutable;
use Tinvar\Data\Data;

/**
 * What a page shows while staff act as its user (ADR 0194): "You are
 * acting as Ada -- stop". Impersonations::banner() makes it; an app hands
 * it to every page as shared data, and @tinvar/react's
 * `<ImpersonationBanner>` draws it.
 */
final readonly class ImpersonationBannerData extends Data
{
    /**
     * @param string $actingAs the user being acted as: their name, or their address without one
     * @param string $impersonator who is acting as them, the same way
     * @param string $reason why, as the impersonator gave it
     * @param CarbonImmutable $until when it ends by itself
     */
    public function __construct(
        public string $actingAs,
        public string $impersonator,
        public string $reason,
        public CarbonImmutable $until,
    ) {}
}
