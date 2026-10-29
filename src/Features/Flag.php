<?php

declare(strict_types=1);

namespace Tinvar\Features;

/**
 * One feature flag the application declares: its name, and what it is where
 * nothing has been set for the tenant, user or panel asking.
 *
 * Declared, so a flag read under a misspelt name fails rather than reading as
 * its default everywhere -- the quiet way a feature stays off, or on, for good.
 */
final readonly class Flag
{
    /** What a flag's name may be: it is a key in stores and in the query cache. */
    public const string NAME = '/^[a-z][a-z0-9]*(?:[-.][a-z0-9]+)*\z/';

    public function __construct(
        public string $name,
        public bool $default = false,
        public string $description = '',
    ) {
        if (\strlen($name) > 64 || preg_match(self::NAME, $name) !== 1) {
            throw new \InvalidArgumentException(
                "Feature flag '{$name}' is not a flag name: lowercase letters and digits, in words joined by '-' or '.', at most 64 characters.",
            );
        }
    }
}
