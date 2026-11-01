<?php

declare(strict_types=1);

/**
 * An attribute that guards its entry point with a middleware of its own,
 * handed the attribute and so its arguments (ADR 0150): a package's
 * `#[RequiresPlan(Plan::Pro)]` is one. Run through the real HTTP Kernel,
 * on a page, a layout, an action, inherited from a base class, and failing
 * closed when nothing can build its middleware.
 */

namespace Tinvar\Tests\Http\MiddlewareAttributes;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Http\AttributeMiddleware;
use Tinvar\Http\DeclaredMiddleware;
use Tinvar\Http\HttpException;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\MiddlewareAttribute;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;

/** A badge a request must carry in `x-badge`, any of those named. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class RequiresBadge implements MiddlewareAttribute
{
    /** @var list<string> */
    public array $badges;

    public function __construct(string ...$badges)
    {
        $this->badges = array_values($badges);
    }

    public function middleware(): string
    {
        return CheckBadge::class;
    }
}

/** Never buildable: a package nobody registered. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RequiresUnregistered implements MiddlewareAttribute
{
    public function middleware(): string
    {
        return Unregistered::class;
    }
}

final class Unregistered implements AttributeMiddleware
{
    public function handle(MiddlewareAttribute $attribute, Request $request, \Closure $next): Response
    {
        return $next($request);
    }
}

final class CheckBadge implements AttributeMiddleware
{
    /** @var list<string> what each run was handed, in order */
    public static array $ran = [];

    public function handle(MiddlewareAttribute $attribute, Request $request, \Closure $next): Response
    {
        \assert($attribute instanceof RequiresBadge);
        self::$ran[] = implode('|', $attribute->badges);
        if (!\in_array($request->header('x-badge'), $attribute->badges, true)) {
            throw new HttpException(402, 'This needs the ' . implode(' or ', $attribute->badges) . ' badge.', 'badge_required');
        }

        return $next($request);
    }
}

final class Order implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        CheckBadge::$ran[] = 'order';

        return $next($request);
    }
}

final readonly class TitleData extends Data
{
    public function __construct(public string $title) {}
}

#[Page('/gold', view: 'gold'), PublicAccess, RequiresBadge('gold', 'platinum')]
final class GoldPage
{
    public function __invoke(): TitleData
    {
        return new TitleData('Gold');
    }
}

#[Page('/ordered', view: 'ordered'), PublicAccess, UseMiddleware(Order::class)]
final class OrderedPage
{
    #[RequiresBadge('gold')]
    public function __invoke(): TitleData
    {
        return new TitleData('Ordered');
    }
}

#[Layout('/members', view: 'members/layout'), RequiresBadge('member')]
final class MembersLayout
{
    public function __invoke(): ?TitleData
    {
        return null;
    }
}

#[Page('/members/lounge', view: 'members/lounge'), PublicAccess]
final class LoungePage
{
    public function __invoke(): TitleData
    {
        return new TitleData('Lounge');
    }
}

#[RequiresBadge('staff')]
abstract class StaffPage {}

#[Page('/staff', view: 'staff'), PublicAccess]
final class StaffRoom extends StaffPage
{
    public function __invoke(): TitleData
    {
        return new TitleData('Staff');
    }
}

#[Page('/nowhere', view: 'nowhere'), PublicAccess, RequiresUnregistered]
final class UnguardedPage
{
    public function __invoke(): TitleData
    {
        return new TitleData('Should never load');
    }
}

final class BadgeActions
{
    #[Action(name: 'badges.export'), PublicAccess, NoAuthorization(reason: 'a fixture with no model input'), RequiresBadge('exporter')]
    public function export(): ActionResult
    {
        return ActionResult::ok(['exported' => true]);
    }
}

/** @param list<\Throwable> $reported */
function kernel(array &$reported = []): Kernel
{
    $routes = new RouteRegistry();
    $routes->registerAll([GoldPage::class, OrderedPage::class, MembersLayout::class, LoungePage::class, StaffRoom::class, UnguardedPage::class]);
    $actions = new ActionRegistry();
    $actions->register(BadgeActions::class);

    return new Kernel(
        orm: new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => $class === Unregistered::class
            ? throw new \LogicException('Nothing is registered for ' . Unregistered::class . '.')
            : new $class(),
        allowedQueries: static fn (Request $r): array => [],
        log: static function (\Throwable $e) use (&$reported): void {
            $reported[] = $e;
        },
    );
}

function page(): \Closure
{
    return static fn (LoadedRoute $loaded, Request $request): Response => Response::make(200, 'loaded', ['content-type' => 'text/html; charset=utf-8']);
}

function visit(string $path, ?string $badge = null): Request
{
    return new Request('GET', $path, $badge === null ? [] : ['x-badge' => $badge]);
}

function action(string $name, ?string $badge = null): Request
{
    return new Request('POST', "/_action/{$name}", [
        'content-type' => 'application/json',
        'accept' => 'application/json',
        VerifyCsrfToken::HEADER => 't',
        ...($badge === null ? [] : ['x-badge' => $badge]),
    ], cookies: [VerifyCsrfToken::COOKIE => 't']);
}

beforeEach(function () {
    CheckBadge::$ran = [];
});

it('hands the middleware the attribute, so it reads its arguments', function () {
    $kernel = kernel();

    expect($kernel->handle(visit('/gold'), page())->status)->toBe(402)
        ->and($kernel->handle(visit('/gold', 'bronze'), page())->status)->toBe(402)
        ->and($kernel->handle(visit('/gold', 'platinum'), page())->status)->toBe(200)
        ->and(CheckBadge::$ran)->toBe(['gold|platinum', 'gold|platinum', 'gold|platinum']);
});

it('guards an action as it guards a page', function () {
    $kernel = kernel();

    expect($kernel->handle(action('badges.export'), page(), '{}')->status)->toBe(402)
        ->and($kernel->handle(action('badges.export', 'exporter'), page(), '{}')->status)->toBe(200);
});

it('runs after the class\'s #[Middleware] when it is written on the method', function () {
    kernel()->handle(visit('/ordered', 'gold'), page());

    expect(CheckBadge::$ran)->toBe(['order', 'gold']);
});

it('holds every page under a layout that carries it', function () {
    $kernel = kernel();

    expect($kernel->handle(visit('/members/lounge'), page())->status)->toBe(402)
        ->and($kernel->handle(visit('/members/lounge', 'member'), page())->status)->toBe(200);
});

it('is inherited from a base class, as #[Middleware] is', function () {
    $kernel = kernel();

    expect($kernel->handle(visit('/staff'), page())->status)->toBe(402)
        ->and($kernel->handle(visit('/staff', 'staff'), page())->status)->toBe(200);
});

it('fails closed when nothing can build its middleware: the request is refused, never let past', function () {
    $reported = [];
    $response = kernel($reported)->handle(visit('/nowhere'), page());

    expect($response->status)->toBe(500)
        ->and($response->body())->not->toContain('loaded')
        ->and($reported[0]->getMessage())->toContain('could not be built, so the request is refused');
});

it('refuses a reference that no longer points at the attribute it names', function () {
    expect(DeclaredMiddleware::on(GoldPage::class))->toBe([RequiresBadge::class . '@' . GoldPage::class . '#0'])
        ->and(fn () => DeclaredMiddleware::attribute(RequiresUnregistered::class . '@' . GoldPage::class . '#0'))->toThrow(\LogicException::class, 'points at no')
        ->and(fn () => DeclaredMiddleware::attribute(RequiresBadge::class . '@' . GoldPage::class . '#3'))->toThrow(\LogicException::class, 'points at no');
});
