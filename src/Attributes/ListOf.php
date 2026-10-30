<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A #[Config] key that is a list: an `array` parameter whose variable is
 * its items, comma-separated -- `TINVAR_TRUSTED=10.0.0.1,10.0.0.2` -- each
 * read as $of reads one, as strictly as a key of that type alone (ADR
 * 0111).
 *
 * $of is string, int, float, bool or a backed enum's class. Spaces around
 * an item are not part of it; an empty item (`a,,b`, a trailing comma) is
 * refused rather than skipped, and so is a string item holding nothing
 * but spaces.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    public function __construct(public string $of) {}
}
