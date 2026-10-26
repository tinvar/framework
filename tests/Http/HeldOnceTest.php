<?php

declare(strict_types=1);

/**
 * `X-Have-Once` through the Kernel: which requests may have a #[Once] field
 * left out, and what still runs when one is.
 *
 * ADR 0020. "Client-held data ... is bound to (user, tenant)": what the
 * browser keeps under a once key was rendered for one session, so the list
 * counts only beside a route token -- `X-Once-Binding` -- naming the session,
 * tenant, panel and build this response is rendered for. And a held field
 * skips its own resolver and nothing else: every middleware, check and loader
 * runs as it does for the whole page, as ADR 0015 requires of partial reloads.
 */

namespace Tinvar\Tests\Http\HeldOnce;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\RouteToken;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

/** What ran, in order: loaders, guards and the once field's resolver. */
final class Ran
{
    /** @var list<string> */
    public static array $events = [];

    /** Set to make the layout's own guard refuse. */
    public static bool $refuse = false;

    /** The build the last Kernel made serves, which a payload request must name. */
    public static string $build = 'b1';
}

final readonly class ShellData extends Data
{
    public function __construct(public string $name) {}
}

final readonly class AccountPageData extends Data
{
    /** @param Lazy<int> $totals */
    public function __construct(
        public string $heading,
        #[Once(key: 'account.totals', until: '10m')]
        public Lazy $totals,
    ) {}
}

#[Layout('/', view: 'shell/layout')]
final class ShellLayout
{
    public function __invoke(): ShellData
    {
        Ran::$events[] = 'layout';
        if (Ran::$refuse) {
            throw new ForbiddenException('Not this shell.');
        }

        return new ShellData('Shell');
    }
}

#[Page('/account', view: 'shell/account', name: 'account'), Authorize('view', AccountPageData::class)]
final class AccountPage
{
    public function __invoke(): AccountPageData
    {
        Ran::$events[] = 'page';

        return new AccountPageData('Account', Lazy::of(static function (): int {
            Ran::$events[] = 'totals';

            return 42;
        }));
    }
}

final class RecordingGate implements Gate
{
    public function allows(Authorize $check, array $arguments): bool
    {
        Ran::$events[] = "check:{$check->ability}";

        return true;
    }
}

const KEY = 'held-once-test-key-0123456789abcdef';

/**
 * A Kernel whose session is whatever `X-Test-Session` says -- none at all
 * when it says nothing -- and whose route tokens are signed with $key.
 */
function kernel(string $key = KEY, string $build = 'b1'): Kernel
{
    Ran::$events = [];
    Ran::$refuse = false;
    Ran::$build = $build;
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    $routes = new RouteRegistry();
    $routes->registerAll([ShellLayout::class, AccountPage::class]);

    return new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        gate: new RecordingGate(),
        fragments: new FragmentSupport(
            signer: new Signer($key, 'fragment'),
            builds: new BuildRegistry($build),
            render: static fn (): mixed => null,
            sessionId: static fn (Request $r): string => $r->header('x-test-session') ?? '',
            routeSigner: new Signer($key, RouteToken::PURPOSE),
        ),
    );
}

/** The page's encoded data, as the renderer would be handed it. */
function respond(): \Closure
{
    return static fn (LoadedRoute $loaded, Request $r): Response => Response::make(
        200,
        json_encode($loaded->encode(new Encoder()), JSON_THROW_ON_ERROR),
        ['content-type' => 'application/json'],
    );
}

/** A route token for the page, minted for a session by a first visit. */
function tokenFor(Kernel $kernel, string $session): string
{
    $response = $kernel->handle(
        Request::get('/account', ['RSC' => '1', 'X-Version' => Ran::$build, 'X-Test-Session' => $session]),
        respond(),
    );

    return (string) $response->header('x-route-token');
}

/**
 * @param array<string, string> $headers
 * @return array{array<string, mixed>, Response}
 */
function visit(Kernel $kernel, array $headers): array
{
    Ran::$events = [];
    // A payload request names its build, or it is told to reload.
    if (isset($headers['RSC'])) {
        $headers['X-Version'] = Ran::$build;
    }
    $response = $kernel->handle(Request::get('/account', $headers), respond());

    /** @var array<string, mixed> $data */
    $data = $response->status === 200 ? json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR) : [];

    return [$data, $response];
}

/** @param array<string, mixed> $data */
function totals(array $data): mixed
{
    return $data['page']['totals'] ?? null;
}

describe('a held once field is left out', function () {
    it('for a client that kept it under this session', function () {
        $kernel = kernel();
        $token = tokenFor($kernel, 'session-a');

        [$data] = visit($kernel, [
            'RSC' => '1',
            'X-Test-Session' => 'session-a',
            'X-Have-Once' => 'account.totals',
            'X-Once-Binding' => $token,
        ]);

        expect(totals($data))->toBe(['$once' => 'account.totals', 'held' => true])
            ->and($data['page']['heading'])->toBe('Account');
    });

    // Fail closed, as a partial reload does: a client listing a key must
    // never be able to choose which of a page's guards run.
    it('with every check and loader run, and only its resolver skipped', function () {
        $kernel = kernel();
        $token = tokenFor($kernel, 'session-a');

        visit($kernel, ['RSC' => '1', 'X-Test-Session' => 'session-a', 'X-Have-Once' => 'account.totals', 'X-Once-Binding' => $token]);

        expect(Ran::$events)->toBe(['check:view', 'layout', 'page']);
    });

    it('and a guard in a loader still refuses', function () {
        $kernel = kernel();
        $token = tokenFor($kernel, 'session-a');
        Ran::$refuse = true;

        [, $response] = visit($kernel, ['RSC' => '1', 'X-Test-Session' => 'session-a', 'X-Have-Once' => 'account.totals', 'X-Once-Binding' => $token]);

        expect($response->status)->toBe(403)
            ->and(Ran::$events)->toBe(['check:view', 'layout']);
    });
});

describe('a once field is sent whole', function () {
    it('without a list', function () {
        $kernel = kernel();

        [$data] = visit($kernel, ['RSC' => '1', 'X-Test-Session' => 'session-a']);

        expect(totals($data))->toBe(['$once' => 'account.totals', 'ttl' => 600, 'tags' => [], 'value' => 42])
            ->and(Ran::$events)->toBe(['check:view', 'layout', 'page', 'totals']);
    });

    it('beside no binding at all', function () {
        $kernel = kernel();

        [$data] = visit($kernel, ['RSC' => '1', 'X-Test-Session' => 'session-a', 'X-Have-Once' => 'account.totals']);

        expect(totals($data))->toHaveKey('value');
    });

    // Signed in as someone else since: what was kept is the last session's.
    it('beside the token of another session', function () {
        $kernel = kernel();
        $theirs = tokenFor($kernel, 'session-b');

        [$data] = visit($kernel, ['RSC' => '1', 'X-Test-Session' => 'session-a', 'X-Have-Once' => 'account.totals', 'X-Once-Binding' => $theirs]);

        expect(totals($data))->toHaveKey('value');
    });

    it('beside a token this server did not sign', function () {
        $forged = tokenFor(kernel('another-key-that-is-long-enough-0000'), 'session-a');
        $kernel = kernel();

        [$data] = visit($kernel, ['RSC' => '1', 'X-Test-Session' => 'session-a', 'X-Have-Once' => 'account.totals', 'X-Once-Binding' => $forged]);

        expect(totals($data))->toHaveKey('value');
    });

    // Kept by the client components of a build that is gone.
    it('beside a token of another build', function () {
        $old = tokenFor(kernel(build: 'b0'), 'session-a');
        $kernel = kernel();

        [$data] = visit($kernel, ['RSC' => '1', 'X-Test-Session' => 'session-a', 'X-Have-Once' => 'account.totals', 'X-Once-Binding' => $old]);

        expect(totals($data))->toHaveKey('value');
    });

    // The client files once content under the binding a response carries;
    // one with none could not be filled from anything it kept.
    it('to a visitor with no session, who gets no route token', function () {
        $kernel = kernel();
        $token = tokenFor($kernel, 'session-a');

        [$data, $response] = visit($kernel, ['RSC' => '1', 'X-Have-Once' => 'account.totals', 'X-Once-Binding' => $token]);

        expect($response->header('x-route-token'))->toBeNull()
            ->and(totals($data))->toHaveKey('value');
    });

    // A document load is always the whole document; no browser sends these
    // headers with one, and nothing kept could fill it.
    it('in a document', function () {
        $kernel = kernel();
        $token = tokenFor($kernel, 'session-a');

        [$data] = visit($kernel, ['X-Test-Session' => 'session-a', 'X-Have-Once' => 'account.totals', 'X-Once-Binding' => $token]);

        expect(totals($data))->toHaveKey('value');
    });

    it('when the list cannot be read whole', function (string $list) {
        $kernel = kernel();
        $token = tokenFor($kernel, 'session-a');

        [$data] = visit($kernel, ['RSC' => '1', 'X-Test-Session' => 'session-a', 'X-Have-Once' => $list, 'X-Once-Binding' => $token]);

        expect(totals($data))->toHaveKey('value');
    })->with([
        'a malformed key beside it' => ['account.totals, not a key'],
        'too long' => [str_repeat('k,', 3000) . 'account.totals'],
    ]);
});

it('says a payload varies on what the client holds', function () {
    $kernel = kernel();

    [, $response] = visit($kernel, ['RSC' => '1', 'X-Test-Session' => 'session-a']);

    expect($response->header('vary'))->toContain('X-Have-Once')
        ->toContain('X-Once-Binding')
        ->toContain('X-Reset');
});
