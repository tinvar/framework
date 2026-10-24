<?php

declare(strict_types=1);

namespace Tinvar\Testing;

/** A trip a TestClock is about to take: `travel(5)->minutes()`, or back with a negative amount. */
final readonly class Travel
{
    public function __construct(private TestClock $clock, private int $amount) {}

    public function seconds(): void
    {
        $this->clock->advance($this->amount);
    }

    public function minutes(): void
    {
        $this->clock->advance($this->amount * 60);
    }

    public function hours(): void
    {
        $this->clock->advance($this->amount * 3600);
    }

    public function days(): void
    {
        $this->clock->advance($this->amount * 86_400);
    }
}
