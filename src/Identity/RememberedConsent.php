<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/** One domain a person is not asked about, as a page listing them shows it. */
final readonly class RememberedConsent
{
    public function __construct(
        public string $origin,
        public string $tenant,
        public string $panel,
        public int $rememberedAt,
        public int $expiresAt,
    ) {}
}
