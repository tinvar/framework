<?php

declare(strict_types=1);

namespace Tinvar\Query;

use Tinvar\Attributes\Authorize;

/** The Phase 0 stub: records that the check ran, and permits it. */
final class AllowAllGate implements Gate
{
    /** @var list<array{ability: string, argument: string|null}> */
    public private(set) array $checks = [];

    public function allows(Authorize $check, array $arguments): bool
    {
        $this->checks[] = ['ability' => $check->ability, 'argument' => $check->argument];

        return true;
    }
}
