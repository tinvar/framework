<?php

declare(strict_types=1);

namespace Tinvar\Features;

/**
 * Who a flag is read for: the tenant, the user and the panel of the request,
 * job or render in hand. A flag is set per each of the three, and the spec
 * keys a query's cached answer by all three and "the feature flags the
 * query read".
 */
final readonly class FlagScope
{
    /**
     * @param string $tenant the tenant in force; '' for none
     * @param string|null $user the signed-in user's id; null for a guest
     * @param string|null $panel the panel in force; null for none
     */
    public function __construct(
        public string $tenant = '',
        public ?string $user = null,
        public ?string $panel = null,
    ) {}

    /**
     * The scope as one string, for a memo. Hex, so the parts -- any bytes,
     * an id is the application's -- cannot run into each other, and a guest
     * is told apart from a user whose id is ''.
     */
    public function key(): string
    {
        $hex = static fn (?string $part): string => $part === null ? '-' : 'x' . bin2hex($part);

        return $hex($this->tenant) . ':' . $hex($this->user) . ':' . $hex($this->panel);
    }
}
