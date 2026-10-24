<?php

declare(strict_types=1);

namespace Tinvar\Testing;

/**
 * The clock a test moves: `travel(5)->minutes()`.
 *
 * The framework has no clock service. Each part that reads the time takes a
 * closure for it -- seconds for the Dispatcher, the Worker, the throttle
 * stores and the build registry, a float for the cache, a date for the
 * scheduler's console -- so this hands out one of each, all reading the same
 * moment. An application built for a test is given them where it would take
 * the system's, and then every part of it moves together.
 *
 * Frozen: time passes only when the test says so.
 */
final class TestClock
{
    private float $now;

    public function __construct(int|float|\DateTimeInterface|null $at = null)
    {
        $this->now = self::toSeconds($at ?? time());
    }

    /** Seconds since the epoch. */
    public function now(): int
    {
        return (int) floor($this->now);
    }

    public function microtime(): float
    {
        return $this->now;
    }

    public function date(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . \sprintf('%.6F', $this->now))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return \Closure(): int for a part that takes seconds */
    public function seconds(): \Closure
    {
        return $this->now(...);
    }

    /** @return \Closure(): float for a part that takes fractional seconds */
    public function floats(): \Closure
    {
        return $this->microtime(...);
    }

    /** @return \Closure(): \DateTimeImmutable for a part that takes a date */
    public function dates(): \Closure
    {
        return $this->date(...);
    }

    /** Moves the clock on by $amount of the unit the returned Travel names. */
    public function travel(int $amount): Travel
    {
        return new Travel($this, $amount);
    }

    /** Sets the clock to $at, forwards or back. */
    public function travelTo(int|float|\DateTimeInterface $at): void
    {
        $this->now = self::toSeconds($at);
    }

    /** @internal Travel's */
    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    private static function toSeconds(int|float|\DateTimeInterface $at): float
    {
        return $at instanceof \DateTimeInterface ? (float) $at->format('U.u') : (float) $at;
    }
}
