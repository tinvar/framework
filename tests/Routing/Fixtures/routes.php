<?php

declare(strict_types=1);

namespace Tinvar\Tests\Routing\Fixtures;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\Page;
use Tinvar\Data\Data;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;

/** Records the order loaders and middleware ran in, for assertions. */
final class Trace
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }

    public static function note(string $event): void
    {
        self::$events[] = $event;
    }
}

final readonly class RootLayoutData extends Data
{
    public function __construct(public string $tenant) {}
}

final readonly class SiteLayoutData extends Data
{
    public function __construct(public string $code) {}
}

final readonly class RackShowData extends Data
{
    public function __construct(public string $rack, public string $siteCode) {}
}

final class RecordingMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Trace::note('middleware:' . static::class);

        return $next($request);
    }
}

final class OuterMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Trace::note('middleware:outer');

        return $next($request);
    }
}

final class InnerMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Trace::note('middleware:inner');

        return $next($request);
    }
}

/** Refuses before anything downstream runs. */
final class BlockingMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Trace::note('middleware:blocking');

        return Response::make(503, 'blocked');
    }
}

#[Layout('/', view: 'root'), UseMiddleware(OuterMiddleware::class)]
final class RootLayout
{
    public function __invoke(): RootLayoutData
    {
        Trace::note('layout:root');

        return new RootLayoutData('acme');
    }
}

#[Layout('/sites/{site}', view: 'sites/layout'), Authorize('view', 'site')]
final class SiteLayout
{
    public function __invoke(Site $site, RootLayoutData $root): SiteLayoutData
    {
        Trace::note('layout:site');

        return new SiteLayoutData($site->code . '@' . $root->tenant);
    }
}

#[Page('/sites/{site}/racks/{rack}', view: 'racks/show', name: 'racks.show')]
#[Authorize('view', 'rack')]
#[UseMiddleware(InnerMiddleware::class)]
final class RackShowPage
{
    public function __invoke(Rack $rack, SiteLayoutData $site): RackShowData
    {
        Trace::note('page:rack-show');

        return new RackShowData($rack->name, $site->code);
    }
}

/** A static segment that must win over /sites/{site}. */
#[Page('/sites/new', view: 'sites/new', name: 'sites.new')]
final class SiteNewPage
{
    public function __invoke(): RootLayoutData
    {
        Trace::note('page:site-new');

        return new RootLayoutData('new');
    }
}

/** "Loaders are optional": no __invoke, but it still carries its rules. */
#[Page('/health', view: 'health', name: 'health'), UseMiddleware(InnerMiddleware::class)]
final class HealthPage {}

#[Page('/counts/{count}', view: 'counts', name: 'counts')]
final class TypedParamPage
{
    public function __invoke(int $count): RootLayoutData
    {
        return new RootLayoutData((string) $count);
    }
}

#[Page('/bad-return', view: 'bad', name: 'bad')]
final class BadReturnPage
{
    public function __invoke(): mixed
    {
        return ['not' => 'data'];
    }
}

#[Page('/orphan', view: 'orphan', name: 'orphan'), Authorize('view', 'ghost')]
final class OrphanCheckPage
{
    public function __invoke(): RootLayoutData
    {
        return new RootLayoutData('x');
    }
}

// Declared but never registered by the happy-path fixtures: registering one is
// how the compiler's rejections are tested.

#[Page('/bad/{1nvalid}', view: 'x', name: 'bad.param')]
final class BadParameterNamePage {}

#[Page('/bad/{a}/{a}', view: 'x', name: 'bad.dupe')]
final class DuplicateParameterPage {}

#[Page('/bad/seg ment', view: 'x', name: 'bad.segment')]
final class BadSegmentPage {}

#[Page('/racks/..', view: 'x', name: 'bad.dots')]
final class DotDotSegmentPage {}

#[Page('/racks/./units', view: 'x', name: 'bad.dot')]
final class DotSegmentPage {}

#[Page("/racks\n", view: 'x', name: 'bad.newline')]
final class TrailingNewlineSegmentPage {}

final class NotARoute {}

/** Class-level check: the spec writes Authorize('viewAny', Rack::class). */
#[Page('/racks', view: 'racks/index', name: 'racks.index'), Authorize('viewAny', Rack::class)]
final class RackIndexPage
{
    public function __invoke(): RootLayoutData
    {
        Trace::note('page:rack-index');

        return new RootLayoutData('all');
    }
}

/**
 * A check naming a parameter that is only a raw path segment, never resolved
 * to a model. Must fail closed rather than hand the policy a string.
 */
#[Page('/raw/{thing}', view: 'raw', name: 'raw'), Authorize('view', 'thing')]
final class RawSubjectPage
{
    public function __invoke(string $thing): RootLayoutData
    {
        Trace::note('page:raw');

        return new RootLayoutData($thing);
    }
}

// --- fixtures for the review findings -------------------------------------

/** A page that spells the layout's parameter differently. Must be refused. */
#[Page('/sites/{siteId}/leak', view: 'leak', name: 'leak')]
final class MismatchedParameterPage
{
    public function __invoke(): RootLayoutData
    {
        Trace::note('page:leak');

        return new RootLayoutData('UNGUARDED');
    }
}

/** Shares a layout key with SiteLayout (both derive 'sites'). */
#[Layout('/sites/{site}/racks', view: 'sites/inner')]
final class CollidingKeyLayout
{
    public function __invoke(): SiteLayoutData
    {
        return new SiteLayoutData('collide');
    }
}

abstract readonly class BasePanelData extends Data
{
    public function __construct(public string $label) {}
}

final readonly class ConcretePanelData extends BasePanelData {}

#[Layout('/panel', view: 'panel/layout')]
final class PanelLayout
{
    public function __invoke(): ConcretePanelData
    {
        return new ConcretePanelData('panel');
    }
}

/** Declares the base class; "by type" must resolve it to the concrete one. */
#[Page('/panel/show', view: 'panel/show', name: 'panel.show')]
final class PanelShowPage
{
    public function __invoke(BasePanelData $panel): RootLayoutData
    {
        return new RootLayoutData($panel->label);
    }
}

#[Page('/flag/{on}', view: 'flag', name: 'flag')]
final class BoolParamPage
{
    public function __invoke(bool $on): RootLayoutData
    {
        return new RootLayoutData($on ? 'yes' : 'no');
    }
}

enum Shade: string
{
    case Dark = 'dark';
    case Light = 'light';
}

#[Page('/shade/{shade}', view: 'shade', name: 'shade')]
final class EnumParamPage
{
    public function __invoke(Shade $shade): RootLayoutData
    {
        return new RootLayoutData($shade->value);
    }
}

/** A loader parameter the router cannot bind: refused at registration. */
#[Page('/unbindable', view: 'unbindable', name: 'unbindable')]
final class UnbindablePage
{
    public function __invoke(\DateTimeImmutable $when): RootLayoutData
    {
        return new RootLayoutData('x');
    }
}

/** Optional model: the route family does not always carry it. */
#[Layout('/optional', view: 'optional/layout'), Authorize('view', 'rack')]
final class OptionalModelLayout
{
    public function __invoke(?Rack $rack = null): RootLayoutData
    {
        return new RootLayoutData($rack === null ? 'none' : $rack->name);
    }
}

#[Page('/optional/list', view: 'optional/list', name: 'optional.list')]
final class OptionalListPage
{
    public function __invoke(RootLayoutData $root): RootLayoutData
    {
        return new RootLayoutData($root->tenant);
    }
}

/** Two layouts returning the same Data class: ambiguous by type. */
#[Layout('/dupe', view: 'dupe/outer')]
final class DupeOuterLayout
{
    public function __invoke(): SiteLayoutData
    {
        return new SiteLayoutData('outer');
    }
}

#[Layout('/dupe/inner', view: 'dupe-inner/layout')]
final class DupeInnerLayout
{
    public function __invoke(): SiteLayoutData
    {
        return new SiteLayoutData('inner');
    }
}

#[Page('/dupe/inner/page', view: 'dupe/page', name: 'dupe.page')]
final class DupePage
{
    public function __invoke(): RootLayoutData
    {
        return new RootLayoutData('dupe');
    }
}

// Same depth, same literal count: precedence must not depend on order.
#[Page('/amb/new/{x}', view: 'amb/a', name: 'amb.a')]
final class AmbiguousLiteralFirstPage
{
    public function __invoke(string $x): RootLayoutData
    {
        return new RootLayoutData('literal-first');
    }
}

#[Page('/amb/{s}/edit', view: 'amb/b', name: 'amb.b')]
final class AmbiguousParamFirstPage
{
    public function __invoke(string $s): RootLayoutData
    {
        return new RootLayoutData('param-first');
    }
}

/**
 * A page whose loader takes a model and declares no check at all. Registering
 * it must fail: this is the fail-open shape the build rules exist to catch.
 */
#[Page('/unguarded/{rack}', view: 'unguarded', name: 'unguarded')]
final class UnguardedModelPage
{
    public function __invoke(Rack $rack): RootLayoutData
    {
        return new RootLayoutData($rack->name);
    }
}
