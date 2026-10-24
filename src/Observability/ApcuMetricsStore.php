<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Counts in APCu: shared by every worker thread of one FrankenPHP process,
 * so a scrape of any of them reads what all of them counted -- as the
 * throttle counts there (ApcuThrottleStore).
 *
 * Not shared with another process: the queue worker and the scheduler run
 * as processes of their own, with an APCu of their own, if any. What they
 * would count needs a store every process opens, as SqliteMetricsStore is.
 */
final class ApcuMetricsStore implements MetricsStore
{
    public function __construct(private readonly string $prefix = 'tinvar:metrics:')
    {
        if (!self::available()) {
            throw new \LogicException('APCu is not loaded, or not enabled for this SAPI.');
        }
    }

    public static function available(): bool
    {
        return \function_exists('apcu_enabled') && apcu_enabled();
    }

    public function add(array $deltas): void
    {
        foreach ($deltas as $key => $delta) {
            $entry = $this->prefix . $key;
            apcu_inc($entry, $delta, $success);
            // A series not yet there: added, unless another thread added it
            // first, in which case it is there to add to now.
            if (!$success && !apcu_add($entry, $delta)) {
                apcu_inc($entry, $delta);
            }
        }
    }

    public function all(): array
    {
        $values = [];
        foreach (new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/', APC_ITER_KEY | APC_ITER_VALUE) as $entry) {
            if (\is_array($entry) && \is_string($entry['key'] ?? null) && \is_int($entry['value'] ?? null)) {
                $values[substr($entry['key'], \strlen($this->prefix))] = $entry['value'];
            }
        }

        return $values;
    }
}
