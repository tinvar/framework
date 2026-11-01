<?php

declare(strict_types=1);

namespace Tinvar\Routing;

/** What HostPattern::compile() refuses in a pattern, and why. */
final class HostPatternRefused extends \InvalidArgumentException
{
    public function __construct(public readonly string $pattern, public readonly string $reason)
    {
        parent::__construct("The host pattern '{$pattern}' {$reason}.");
    }
}
