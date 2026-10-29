<?php

declare(strict_types=1);

namespace Tinvar\Tests\Features\Fixtures;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\StaticPage;
use Tinvar\Features\Features;
use Tinvar\Features\FlagReader;
use Tinvar\Features\FlagScope;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Query\Fixtures\RackSummaryData;

/** The flags these fixtures read. */
enum Flags: string
{
    case Units = 'rack.units';
}

/**
 * A shared, cached query whose answer turns on a flag: the rack's units
 * counted where `rack.units` is on, none where it is off. Shared, so the
 * user is not in its key -- only the flag it read can keep two users'
 * answers apart.
 */
#[Query(name: 'racks.flagged', cache: '5m', shared: true), NoAuthorization(reason: 'fixture: exercises flags in the query cache')]
final class FlaggedRack
{
    public static int $runs = 0;

    public function __construct(private readonly FlagReader $features) {}

    public function __invoke(Rack $rack): RackSummaryData
    {
        self::$runs++;

        return new RackSummaryData($rack->name, $this->features->active(Flags::Units) ? $rack->units->count() : 0);
    }
}

/** A cached query that reads no flag, run in the same tick as the others. */
#[Query(name: 'racks.plain', cache: '5m', shared: true), NoAuthorization(reason: 'fixture: exercises flags in the query cache')]
final class PlainRack
{
    public static int $runs = 0;

    public function __invoke(Rack $rack): RackSummaryData
    {
        self::$runs++;

        return new RackSummaryData($rack->name, 0);
    }
}

/**
 * A cached query that reads its flag in a fiber of its own, where no
 * recorder can tell whose read it was.
 */
#[Query(name: 'racks.elsewhere', cache: '5m', shared: true), NoAuthorization(reason: 'fixture: exercises flags in the query cache')]
final class FlagReadElsewhere
{
    public static int $runs = 0;

    public function __construct(private readonly FlagReader $features) {}

    public function __invoke(Rack $rack): RackSummaryData
    {
        self::$runs++;
        $fiber = new \Fiber(fn (): bool => $this->features->active(Flags::Units));
        $fiber->start();

        return new RackSummaryData($rack->name, $fiber->getReturn() === true ? 1 : 0);
    }
}

/** A shared query handed the whole Features, which can read any scope. */
#[Query(name: 'racks.anyScope', cache: '5m', shared: true), NoAuthorization(reason: 'fixture: exercises flags in the query cache')]
final class SharedWithFeatures
{
    public function __construct(private readonly Features $features) {}

    public function __invoke(Rack $rack): RackSummaryData
    {
        return new RackSummaryData($rack->name, $this->features->in(Flags::Units, new FlagScope(user: 'u1')) ? 1 : 0);
    }
}

/** A cached query that reads its flag for a scope it names, not its caller's. */
#[Query(name: 'racks.otherScope', cache: '5m'), NoAuthorization(reason: 'fixture: exercises flags in the query cache')]
final class FlagReadForAnotherScope
{
    public static int $runs = 0;

    public function __construct(private readonly Features $features) {}

    public function __invoke(Rack $rack): RackSummaryData
    {
        self::$runs++;

        return new RackSummaryData($rack->name, $this->features->in(Flags::Units, new FlagScope(user: 'u1')) ? 1 : 0);
    }
}

#[Page('/flagged', view: 'flagged', name: 'static.flagged'), StaticPage]
final class FlaggedStaticPage {}
