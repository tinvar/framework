<?php

declare(strict_types=1);

/** Data another namespace declares, imported by value-sites.php under an alias and in a group. */

namespace Tinvar\Tests\Dev\ValueSites\Parts;

use Tinvar\Data\Data;

final readonly class OwnerData extends Data
{
    public function __construct(public string $name) {}
}

final readonly class TotalsData extends Data
{
    public function __construct(public int $sum) {}
}
