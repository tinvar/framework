<?php

declare(strict_types=1);

/**
 * Whose content a held #[Once] key stands for, through the Kernel.
 *
 * ADR 0020. A key names content rendered for one subject. The default key
 * was the class and field alone, so `/customers/2` was claimed with what the
 * browser kept from `/customers/1`: the resolver was skipped and customer 1's
 * summary was put back on customer 2's page. The default is now scoped to the
 * page asked for -- route, parameters and query -- so a key kept for one page
 * is never one another page sends.
 */

namespace Tinvar\Tests\Http\OnceSubject;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Data\Lazy;
use Tinvar\Http\BuildRegistry;
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

/** Whose summaries were summed, in order. */
final class Summed
{
    /** @var list<string> */
    public static array $customers = [];
}

final readonly class CustomerPageData extends Data
{
    /** @param Lazy<string> $summary */
    public function __construct(
        public string $customer,
        #[Once(until: '1h')]
        public Lazy $summary,
    ) {}
}

#[Page('/customers/{id}', view: 'customers/show', name: 'customers.show'), NoAuthorization(reason: 'a test page')]
final class CustomerPage
{
    public function __invoke(string $id): CustomerPageData
    {
        return new CustomerPageData($id, Lazy::of(static function () use ($id): string {
            Summed::$customers[] = $id;

            return "customer {$id} owes \${$id}00";
        }));
    }
}

const KEY = 'once-subject-test-key-0123456789abcdef';

function kernel(): Kernel
{
    Summed::$customers = [];
    $routes = new RouteRegistry();
    $routes->registerAll([CustomerPage::class]);

    return new Kernel(
        orm: new Orm(new Fixture(racks: 1, unitsPerRack: 1, sites: 1)->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        gate: new AllowAllGate(),
        fragments: new FragmentSupport(
            signer: new Signer(KEY, 'fragment'),
            builds: new BuildRegistry('b1'),
            render: static fn (): mixed => null,
            sessionId: static fn (Request $r): string => 'session-a',
            routeSigner: new Signer(KEY, RouteToken::PURPOSE),
        ),
    );
}

/**
 * A payload of a customer's page, as the renderer would be handed it, with
 * its route token.
 *
 * @param array<string, string> $headers
 * @param array<string, string> $query
 * @return array{array<string, mixed>, string}
 */
function visit(Kernel $kernel, string $path, array $headers = [], array $query = []): array
{
    $response = $kernel->handle(
        Request::get($path, ['RSC' => '1', 'X-Version' => 'b1', ...$headers], $query),
        static fn (LoadedRoute $loaded, Request $r): Response => Response::make(
            200,
            json_encode($loaded->encode(new Encoder()), JSON_THROW_ON_ERROR),
            ['content-type' => 'application/json'],
        ),
    );
    expect($response->status)->toBe(200, $response->body());

    /** @var array<string, mixed> $data */
    $data = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

    return [$data['page'], (string) $response->header('x-route-token')];
}

it("never claims one customer's summary for another's page", function () {
    $kernel = kernel();
    [$first, $token] = visit($kernel, '/customers/1');
    $kept = $first['summary']['$once'];

    // The browser lists what it kept from customer 1's page, as it does on
    // every request, with that page's token.
    [$second] = visit($kernel, '/customers/2', ['X-Have-Once' => $kept, 'X-Once-Binding' => $token]);

    expect($second['summary'])->toHaveKey('value')
        ->and($second['summary']['value'])->toBe('customer 2 owes $200')
        ->and($second['summary']['$once'])->not->toBe($kept)
        ->and(Summed::$customers)->toBe(['1', '2']);
});

it('claims it again for the same page', function () {
    $kernel = kernel();
    [$first, $token] = visit($kernel, '/customers/1');
    $kept = $first['summary']['$once'];

    [$again] = visit($kernel, '/customers/1', ['X-Have-Once' => $kept, 'X-Once-Binding' => $token]);

    expect($again['summary'])->toBe(['$once' => $kept, 'held' => true])
        ->and(Summed::$customers)->toBe(['1']);
});

// A loader reads its query as much as its path: `?page=2` may sum something
// else than the page without it.
it('keeps a page apart from the same page with another query', function () {
    $kernel = kernel();
    [$first, $token] = visit($kernel, '/customers/1');

    [$filtered] = visit(
        $kernel,
        '/customers/1',
        ['X-Have-Once' => $first['summary']['$once'], 'X-Once-Binding' => $token],
        ['tab' => 'orders'],
    );

    expect($filtered['summary'])->toHaveKey('value')
        ->and(Summed::$customers)->toBe(['1', '1']);
});
