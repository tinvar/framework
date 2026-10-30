<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A #[Config] key that is a map: an `array` parameter whose variable is
 * its entries, comma-separated, each a key, `=` and a value --
 * `TINVAR_THROTTLE=actions=600,sign-in.address=0` -- each value read as $of
 * reads one, as strictly as a key of that type alone (ADR 0111).
 *
 * A key is letters, digits, `_`, `-` and `.`, and is given once: a key
 * given twice is refused, since nothing says which was meant. $of is
 * string, int, float, bool or a backed enum's class.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class MapOf
{
    public function __construct(public string $of) {}
}
