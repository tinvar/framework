<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\ActionRedirects;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

/**
 * An action's redirect, both ways it is followed.
 *
 * tinvar-spec.md, "HTTP": "ActionResult::redirect(), back() and the 419
 * redirect accept same-origin URLs only. External URLs go through
 * location() with an allowlist." The endpoint answers a redirect with a 303.
 * A browser posting a form itself follows it; the client router, which
 * fetches with `redirect: 'manual'` and cannot read a 303's Location, gets
 * it from the Kernel as a 409 -- X-Redirect for a path on this origin, which
 * it visits, and X-Location for another origin, which it loads as a
 * document (packages/react/src/submit.js).
 */
final class Redirecting
{
    #[Action(name: 'redirects.back'), NoAuthorization(reason: 'a fixture with no model input')]
    public function back(): ActionResult
    {
        return ActionResult::back()->withTags('orders');
    }

    #[Action(name: 'redirects.pay'), NoAuthorization(reason: 'a fixture with no model input')]
    public function pay(): ActionResult
    {
        return ActionResult::location('https://pay.example/checkout?order=7')->withTags('orders');
    }

    #[Action(name: 'redirects.elsewhere'), NoAuthorization(reason: 'a fixture with no model input')]
    public function elsewhere(): ActionResult
    {
        return ActionResult::location('https://evil.example/collect');
    }

    #[Action(name: 'redirects.plain'), NoAuthorization(reason: 'a fixture with no model input')]
    public function plain(): ActionResult
    {
        return ActionResult::location('http://pay.example/checkout');
    }
}

/**
 * @param list<string> $origins the Kernel's redirectOrigins
 * @param list<\Throwable> $logged what the exception handler logs
 * @return array{Kernel, AuthContext}
 */
function kernel(array $origins = [], array &$logged = []): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $auth = new AuthContext();
    $actions = new ActionRegistry();
    $actions->register(Redirecting::class);

    return [new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        log: static function (\Throwable $e) use (&$logged): void {
            $logged[] = $e;
        },
        redirectOrigins: $origins,
    ), $auth];
}

/**
 * Calls an action as a signed-in visitor on https://shop.test/orders?page=2:
 * from script through the client router, or by the browser posting the
 * form itself.
 *
 * @param array{Kernel, AuthContext} $kernel
 */
function call(array $kernel, string $action, bool $script, ?string $referer = 'https://shop.test/orders?page=2'): Response
{
    [$app, $auth] = $kernel;
    $auth->login(new User('u-1'));

    $headers = $script
        ? ['content-type' => 'application/json', 'accept' => 'application/json', 'rsc' => '1']
        : ['content-type' => 'application/x-www-form-urlencoded', 'accept' => 'text/html,application/xhtml+xml'];

    return $app->handle(
        new Request(
            'POST',
            "/_action/{$action}",
            [...$headers, 'x-xsrf-token' => 'tok', ...($referer === null ? [] : ['referer' => $referer])],
            [],
            [],
            [VerifyCsrfToken::COOKIE => 'tok'],
        ),
        static fn (): Response => throw new \LogicException('no page here'),
        $script ? '{}' : '',
    );
}

describe('back()', function () {
    it('sends the client router back to the page it was on, inside the visit', function () {
        $response = call(kernel(), 'redirects.back', script: true);

        expect($response->status)->toBe(409)
            ->and($response->header('x-redirect'))->toBe('/orders?page=2')
            ->and($response->header('x-location'))->toBeNull()
            ->and($response->header('x-invalidated-tags'))->toBe('orders');
    });

    it('sends a browser\'s own form post back with a 303', function () {
        $response = call(kernel(), 'redirects.back', script: false);

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/orders?page=2')
            ->and($response->header('x-invalidated-tags'))->toBe('orders');
    });

    // As the endpoint's own back(): a path on this origin, whatever Referer
    // named, and the root when it named none redirect() would take.
    it('goes back only to a path on this origin', function (?string $referer, string $back) {
        expect(call(kernel(), 'redirects.back', script: false, referer: $referer)->header('location'))->toBe($back)
            ->and(call(kernel(), 'redirects.back', script: true, referer: $referer)->header('x-redirect'))->toBe($back);
    })->with([
        'another site' => ['https://evil.example/steal?x=1', '/steal?x=1'],
        'a protocol-relative path' => ['https://shop.test//evil.example/', '/'],
        'traversal' => ['https://shop.test/a/../../x', '/'],
        'none' => [null, '/'],
    ]);
});

describe('location()', function () {
    it('sends the client router to an allowlisted origin as a document load', function () {
        $response = call(kernel(['https://pay.example']), 'redirects.pay', script: true);

        expect($response->status)->toBe(409)
            ->and($response->header('x-location'))->toBe('https://pay.example/checkout?order=7')
            ->and($response->header('x-redirect'))->toBeNull()
            ->and($response->header('x-invalidated-tags'))->toBe('orders');
    });

    it('sends a browser\'s own form post there with a 303', function () {
        $response = call(kernel(['https://pay.example']), 'redirects.pay', script: false);

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('https://pay.example/checkout?order=7');
    });

    // Failing closed: never a redirect, whoever asks.
    it('is a 500 the handler classifies, never a redirect, off the allowlist', function (array $origins, string $action, string $logged) {
        foreach ([true, false] as $script) {
            $errors = [];
            $response = call(kernel($origins, $errors), $action, script: $script);

            expect($response->status)->toBe(500)
                ->and($response->header('location'))->toBeNull()
                ->and($response->header('x-location'))->toBeNull()
                ->and($response->header('x-redirect'))->toBeNull()
                ->and($response->body())->toContain('internal_error')
                ->and($response->body())->not->toContain('evil.example')
                ->and($errors)->toHaveCount(1)
                ->and($errors[0]->getMessage())->toContain($logged);
        }
    })->with([
        'with no allowlist' => [[], 'redirects.pay', 'on https://pay.example:443, which the application has not allowlisted'],
        'to another origin' => [['https://pay.example'], 'redirects.elsewhere', 'on https://evil.example:443, which'],
        'over plain http' => [['https://pay.example'], 'redirects.plain', 'is not https:// on a host name'],
    ]);

    it('refuses an allowlist entry that is not an https origin, when the Kernel is built', function () {
        expect(fn () => kernel(['http://pay.example']))
            ->toThrow(\InvalidArgumentException::class, "'http://pay.example' is not one.");
    });
});

// The answer to either is a 303 with no body, so there is nowhere for a
// flash or a value to go, as for redirect().
it('refuses a flash or a value on back() or location()', function (\Closure $build, string $where) {
    expect($build)->toThrow(\LogicException::class, "An ActionResult redirecting {$where} cannot also carry a flash or a value");
})->with([
    'back with a flash' => [fn () => ActionResult::back()->flash(['message' => 'Saved']), 'back'],
    'back with a value' => [fn () => ActionResult::back()->withValue(1), 'back'],
    'location with a flash' => [fn () => ActionResult::location('https://pay.example/')->flash(['m' => 'x']), 'off this origin'],
    'location with a value' => [fn () => ActionResult::location('https://pay.example/')->withValue(1), 'off this origin'],
]);

it('keeps back() and location() through the other builders', function () {
    $back = ActionResult::back()->withTags('orders')->withRoutes('orders.index');
    $location = ActionResult::location('https://pay.example/')->withTags('orders');

    expect($back->isRedirect())->toBeTrue()
        ->and($back->back)->toBeTrue()
        ->and($back->status)->toBe(303)
        ->and($location->isRedirect())->toBeTrue()
        ->and($location->location)->toBe('https://pay.example/')
        ->and($location->redirect)->toBeNull()
        ->and(ActionResult::ok()->isRedirect())->toBeFalse();
});
