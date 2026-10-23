<?php

declare(strict_types=1);

namespace Tinvar\Tests\Data\FileScope;

$prefix = 'x';
$closure = static function (string $s) use ($prefix): string {
    return $prefix . $s;
};

use Tinvar\Data\Data;
use Tinvar\Tests\Data\Braced\Parts\Part;

final readonly class AfterClosure extends Data
{
    /** @param list<Part> $parts */
    public function __construct(public array $parts) {}
}
