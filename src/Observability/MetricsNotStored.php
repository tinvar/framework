<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * A store that counted some of a flush and not the rest: the rest, for
 * Metrics to keep for the next flush -- what it counted is not handed to it
 * again, so nothing is counted twice.
 */
final class MetricsNotStored extends \RuntimeException
{
    /** @param array<string, int> $unwritten series key => amount the store did not add, in millionths */
    public function __construct(string $message, public readonly array $unwritten)
    {
        parent::__construct($message);
    }
}
