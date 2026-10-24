<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\FormNavigation;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Flash;
use Tinvar\Http\FlashStore;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Auth\Fixtures\User as AuthUser;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Routing\Fixtures as R;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';
require_once __DIR__ . '/../Actions/Fixtures/actions.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

/**
 * A form posted by the browser itself -- before JavaScript loads, or without
 * it -- and the page it is sent back to.
 *
 * tinvar-spec.md, "Forms and mutations": "Without JavaScript, fall back to
 * redirect-back with flashed errors." The endpoint answered every caller with
 * JSON, so a person whose script had not loaded was shown the raw body: a
 * success as `{"value":…}`, a mistake as `{"errors":…}`.
 */
const SESSION_COOKIE = '__Host-test_session';

/** One flash per session, removed as it is read; what an application's store does. */
final class SessionFlashes implements FlashStore
{
    /** @var array<string, array<string, mixed>> */
    public array $held = [];

    public int $reads = 0;

    public function put(Request $request, array $flash): void
    {
        $this->held[$request->cookie(SESSION_COOKIE) ?? ''] = $flash;
    }

    public function pull(Request $request): array
    {
        $this->reads++;
        $session = $request->cookie(SESSION_COOKIE) ?? '';
        $flash = $this->held[$session] ?? [];
        unset($this->held[$session]);

        return $flash;
    }
}

final class RedirectingActions
{
    #[Action(name: 'forms.finish'), NoAuthorization(reason: 'a fixture with no model input')]
    public function finish(): ActionResult
    {
        return ActionResult::redirect('/done')->withTags('racks');
    }
}

function tokens(): CsrfTokens
{
    return new CsrfTokens(
        new Signer(str_repeat('k', 32), CsrfTokens::PURPOSE),
        static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '',
    );
}

/** @return array{Kernel, string, string, \Closure(): void} the kernel, a page path, a rack id, and a sign-in */
function kernel(?FlashStore $flashes): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 2, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $auth = new AuthContext();

    $routes = new RouteRegistry();
    $routes->registerAll([R\RootLayout::class, R\SiteLayout::class, R\RackShowPage::class]);

    $actions = new ActionRegistry();
    $actions->register(A\RackActions::class);
    $actions->register(RedirectingActions::class);

    $policies = (new PolicyRegistry())->registerAll([
        Rack::class => A\RecordingRackPolicy::class,
        Site::class => A\RecordingSitePolicy::class,
    ]);
    A\RecordingRackPolicy::reset();

    $kernel = new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: $policies,
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        csrf: tokens(),
        flashes: $flashes,
    );

    // The reset contract signs the user out after every request.
    $signIn = static function () use ($auth): void {
        $auth->login(new AuthUser('u-1'));
    };

    return [
        $kernel,
        '/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0],
        $fixture->ids['racks'][0],
        $signIn,
    ];
}

/** The cookies a visitor holds once a page has issued the CSRF one. */
function cookies(): array
{
    $session = [SESSION_COOKIE => 'session-a'];

    return [...$session, VerifyCsrfToken::COOKIE => (string) tokens()->for(new Request('GET', '/', cookies: $session))];
}

/**
 * A form as a browser posts it with no script: urlencoded, the token in the
 * hidden field, asking for a page, from the page the form was on.
 *
 * @param array<string, string> $fields
 * @param array<string, string> $cookies
 */
function submitted(string $action, array $fields, array $cookies, string $referer = 'https://shop.test/racks/r-1?tab=units'): Request
{
    $fields = [...$fields, '_token' => $cookies[VerifyCsrfToken::COOKIE] ?? ''];

    return new Request('POST', "/_action/{$action}", [
        'content-type' => 'application/x-www-form-urlencoded',
        'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'referer' => $referer,
    ], [], $fields, $cookies);
}

/** A page, recording the request its responder was handed. */
function page(?Request &$seen = null): \Closure
{
    return static function (LoadedRoute $loaded, Request $request) use (&$seen): Response {
        $seen = $request;

        return Response::make(200, '<html></html>', ['content-type' => 'text/html; charset=utf-8']);
    };
}

describe('a form posted without script', function () {
    it('goes back to the form with its errors and its input flashed, never its password', function () {
        $flashes = new SessionFlashes();
        [$kernel, , $rack, $signIn] = kernel($flashes);
        $signIn();

        $response = $kernel->handle(
            submitted('RackActions.moveUnit', ['rack' => $rack, 'position' => '99', 'password' => 'hunter2'], cookies()),
            page(),
        );

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/racks/r-1?tab=units')
            ->and($response->cookies())->toHaveKey(Flash::COOKIE)
            ->and($flashes->held['session-a'])->toBe(['form' => [
                'action' => 'RackActions.moveUnit',
                'errors' => ['position' => ['position must be at most 42.']],
                'old' => ['rack' => $rack, 'position' => '99'],
            ]]);
    });

    it('goes back after a success, carrying the action\'s flash and its invalidated tags', function () {
        $flashes = new SessionFlashes();
        [$kernel, , $rack, $signIn] = kernel($flashes);
        $signIn();

        $response = $kernel->handle(submitted('RackActions.moveUnit', ['rack' => $rack, 'position' => '12'], cookies()), page());

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/racks/r-1?tab=units')
            ->and($response->header('x-invalidated-tags'))->toBe("rack:{$rack}")
            ->and($flashes->held['session-a'])->toBe(['flash' => ['message' => 'Moved to 12']]);
    });

    it('follows the action\'s own redirect with a 303', function () {
        [$kernel, , , $signIn] = kernel(new SessionFlashes());
        $signIn();

        $response = $kernel->handle(submitted('forms.finish', [], cookies()), page());

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/done');
    });

    // Back is a path here, whatever Referer named: a form on another site
    // that posted here goes back to that path on this one, and a target
    // redirect() would refuse goes to the root.
    it('goes back only to a path on this site', function (string $referer, string $back) {
        [$kernel, , $rack, $signIn] = kernel(new SessionFlashes());
        $signIn();

        $response = $kernel->handle(
            submitted('RackActions.moveUnit', ['rack' => $rack, 'position' => '12'], cookies(), $referer),
            page(),
        );

        expect($response->header('location'))->toBe($back);
    })->with([
        'another site' => ['https://evil.example/racks?x=1', '/racks?x=1'],
        'protocol-relative path' => ['https://shop.test//evil.example/', '/'],
        'traversal' => ['https://shop.test/a/../../x', '/'],
        'none' => ['', '/'],
    ]);

    // Nowhere to keep the errors, so nowhere to send the person back to with
    // them: the exception handler's page, which says what happened.
    it('gets the 422 page when the application keeps no flashes', function () {
        [$kernel, , $rack, $signIn] = kernel(null);
        $signIn();

        $response = $kernel->handle(
            submitted('RackActions.moveUnit', ['rack' => $rack, 'position' => '99'], cookies()),
            page(),
        );

        expect($response->status)->toBe(422)
            ->and($response->header('content-type'))->toStartWith('text/html');
    });
});

describe('the page it is sent back to', function () {
    it('reads the flash once, hands it to the render, and clears the cookie', function () {
        $flashes = new SessionFlashes();
        [$kernel, $path, $rack, $signIn] = kernel($flashes);
        $signIn();
        $kernel->handle(submitted('RackActions.moveUnit', ['rack' => $rack, 'position' => '99'], cookies()), page());

        $signIn();
        $first = $kernel->handle(new Request('GET', $path, ['accept' => 'text/html'], cookies: [...cookies(), Flash::COOKIE => '1']), page($seen));
        $flash = $seen?->attribute(Kernel::FLASH);

        $signIn();
        $kernel->handle(new Request('GET', $path, ['accept' => 'text/html'], cookies: [...cookies(), Flash::COOKIE => '1']), page($again));

        expect($first->status)->toBe(200)
            ->and($flash['form']['errors'] ?? null)->toBe(['position' => ['position must be at most 42.']])
            ->and($first->cookies()[Flash::COOKIE] ?? '')->toContain('Max-Age=0')
            ->and($again?->attribute(Kernel::FLASH))->toBeNull('consumed by the first page');
    });

    // A page with nothing flashed asks the store nothing: every render
    // would otherwise pay a read for what almost none of them have.
    it('asks the store only when the cookie says a flash is waiting', function () {
        $flashes = new SessionFlashes();
        [$kernel, $path, , $signIn] = kernel($flashes);

        $signIn();
        $response = $kernel->handle(new Request('GET', $path, ['accept' => 'text/html'], cookies: cookies()), page());

        expect($response->status)->toBe(200)
            ->and($flashes->reads)->toBe(0)
            ->and($response->cookies())->not->toHaveKey(Flash::COOKIE);
    });

    // "Prefetch requests skip side effects": hovering a link must not take
    // the errors meant for the page the person opens.
    it('leaves the flash for the page the person opens, not a prefetch', function () {
        $flashes = new SessionFlashes();
        $flashes->held['session-a'] = ['flash' => ['message' => 'Saved']];
        [$kernel, $path, , $signIn] = kernel($flashes);

        $signIn();
        $kernel->handle(
            new Request('GET', $path, ['accept' => 'text/html', 'purpose' => 'prefetch'], cookies: [...cookies(), Flash::COOKIE => '1']),
            page($seen),
        );

        expect($seen?->attribute(Kernel::FLASH))->toBeNull()
            ->and($flashes->held)->toHaveKey('session-a');
    });

    // "<Form> also renders a hidden _token field during dynamic server
    // rendering." The render cannot read the cookie, which this very
    // response may be the one to set.
    it('hands the render the session\'s CSRF token', function () {
        [$kernel, $path, , $signIn] = kernel(null);

        $signIn();
        $kernel->handle(new Request('GET', $path, ['accept' => 'text/html'], cookies: [SESSION_COOKIE => 'session-a']), page($seen));

        expect($seen?->attribute(Kernel::CSRF_TOKEN))
            ->toBe(tokens()->for(new Request('GET', '/', cookies: [SESSION_COOKIE => 'session-a'])));
    });
});

describe('a script caller', function () {
    it('still gets field errors as JSON, with nothing flashed', function () {
        $flashes = new SessionFlashes();
        [$kernel, , $rack, $signIn] = kernel($flashes);
        $cookies = cookies();
        $signIn();

        $response = $kernel->handle(
            new Request('POST', '/_action/RackActions.moveUnit', [
                'content-type' => 'application/json',
                'accept' => 'application/json',
                'x-xsrf-token' => $cookies[VerifyCsrfToken::COOKIE],
            ], cookies: $cookies),
            page(),
            json_encode(['rack' => $rack, 'position' => 99], JSON_THROW_ON_ERROR),
        );

        expect($response->status)->toBe(422)
            ->and(json_decode($response->body(), true)['errors'])->toBe(['position' => ['position must be at most 42.']])
            ->and($flashes->held)->toBe([]);
    });

    // The client router fetches with redirect: 'manual', which hides a
    // 303's Location. It reads an action's redirect as it reads a page's.
    it('reads an action\'s redirect as 409 + X-Redirect from the client router, with the tags', function () {
        [$kernel, , , $signIn] = kernel(null);
        $cookies = cookies();
        $signIn();

        $response = $kernel->handle(
            new Request('POST', '/_action/forms.finish', [
                'content-type' => 'application/json',
                'accept' => 'application/json',
                'rsc' => '1',
                'x-xsrf-token' => $cookies[VerifyCsrfToken::COOKIE],
            ], cookies: $cookies),
            page(),
            '{}',
        );

        expect($response->status)->toBe(409)
            ->and($response->header('x-redirect'))->toBe('/done')
            ->and($response->header('x-invalidated-tags'))->toBe('racks');
    });
});

describe('Flash::old', function () {
    it('keeps the input to put back, and nothing secret', function () {
        expect(Flash::old([
            'email' => 'a@b.test',
            'password' => 'x',
            'new_password_confirmation' => 'x',
            'client_secret' => 'x',
            '_token' => 't',
            '_routes' => ['r'],
            'address' => ['line1' => '1 Road', 'api_token' => 'x'],
        ]))->toBe(['email' => 'a@b.test', 'address' => ['line1' => '1 Road']]);
    });

    it('keeps none of it past the size a flash may hold', function () {
        expect(Flash::old(['notes' => str_repeat('x', Flash::MAX_OLD_BYTES)]))->toBe([]);
    });
});
