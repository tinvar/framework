<?php

declare(strict_types=1);

namespace Tinvar\Discovery;

/**
 * Discovery refused (ADR 0146): every problem it found, named, so one run
 * says all that is wrong -- a file that does not parse or load, a class
 * carrying a framework attribute nothing registers, two policies for one
 * class -- or the manifest production boots from is missing or not one.
 *
 * Nothing is registered from a scan that failed: an application half
 * discovered is one missing pages, policies or checks without saying so.
 */
final class DiscoveryFailed extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems, string $heading = 'Discovery refused the application\'s classes:')
    {
        parent::__construct($heading . "\n  " . implode("\n  ", $problems));
    }
}
