<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use PHPUnit\Framework\Assert;
use Tinvar\Orm\LazyLoad;

/**
 * assertNoLazyLoadsOutsideBatch(), shared by the function and the page and
 * render responses: what was recorded, and the message a failure gives.
 */
final class LazyLoadAssertion
{
    /**
     * Every load in $loads ran in a batch (LazyLoad::inBatch()).
     *
     * @param list<LazyLoad>|null $loads null when nothing recorded them
     */
    public static function assertNoneOutsideBatch(?array $loads, string $subject): void
    {
        if ($loads === null) {
            throw new \LogicException(
                'Lazy relation loads were not recorded: Testing::use() records them for the application under test, '
                . 'and Orm::recordLazyLoads() for an Orm a test built itself.',
            );
        }

        $alone = [];
        foreach ($loads as $load) {
            if (!$load->inBatch()) {
                $alone[$load->relation] = ($alone[$load->relation] ?? 0) + 1;
            }
        }

        Assert::assertSame([], $alone, "{$subject} loaded relations outside a batch, each for one parent alone -- the 1+N the batching exists to prevent: "
            . implode(', ', array_map(
                static fn (string $relation, int $times): string => $relation . ($times === 1 ? '' : " ({$times} times)"),
                array_keys($alone),
                $alone,
            )) . '.');
    }
}
