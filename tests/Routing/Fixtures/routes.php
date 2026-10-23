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

final class NotARoute {}
