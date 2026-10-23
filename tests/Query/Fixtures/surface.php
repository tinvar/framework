<?php

declare(strict_types=1);

namespace Tinvar\Tests\Query\Fixtures\Surface;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Query;
use Tinvar\Query\BatchQuery;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Query\Fixtures\RackSummaryData;

/** Not a query at all: no #[Query] attribute. */
final class NotAQuery
{
    public function __invoke(): int
    {
        return 1;
    }
}

/**
 * The rack is optional, so the check on it only applies when one is given:
 * with no rack there is no subject for the policy to judge.
 */
#[Query(name: 'surface.optionalRack'), Authorize('view', 'rack')]
final class OptionalRackSummary
{
    public function __invoke(?Rack $rack = null): RackSummaryData
    {
        return new RackSummaryData($rack === null ? 'all racks' : $rack->name, 0);
    }
}

/** Carries #[Query] but has neither __invoke nor loadMany. */
#[Query(name: 'surface.inert')]
final class InertQuery {}

/** A batch query whose body fails. */
#[Query(name: 'surface.batchBoom')]
final class ThrowingBatch implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        throw new \RuntimeException('deadlock detected');
    }
}

/** Cached, with no tags to record. */
#[Query(name: 'surface.untagged', cache: '5m')]
final class UntaggedCachedQuery
{
    public static int $runs = 0;

    public function __invoke(): RackSummaryData
    {
        self::$runs++;

        return new RackSummaryData('untagged', self::$runs);
    }
}

/** Cached, with a tag closure that returns something other than strings. */
#[Query(
    name: 'surface.mistagged',
    cache: '5m',
    tags: static function (): array {
        return [42];
    },
)]
final class MistaggedCachedQuery
{
    public function __invoke(): RackSummaryData
    {
        return new RackSummaryData('mistagged', 0);
    }
}
