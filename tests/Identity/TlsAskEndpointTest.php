<?php

declare(strict_types=1);

/**
 * The certificate-renewal half of "re-verified daily and at every
 * certificate renewal": Caddy's on-demand TLS `ask`, at /_tls/ask, which
 * CertMagic consults before it obtains a certificate and before it renews
 * one it manages on demand.
 */

namespace Tinvar\Tests\Identity\TlsAskEndpoint;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\ApcuThrottleStore;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\ThrottleStore;
use Tinvar\Identity\CustomDomain;
use Tinvar\Identity\DnsLookupFailed;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Identity\TlsAskEndpoint;
use Tinvar\Observability\ArrayMetricsStore;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\ProbeAccess;
use Tinvar\Observability\Telemetry;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Identity\Fixtures\Domains\Clock;
use Tinvar\Tests\Identity\Fixtures\Domains\Dns;
use Tinvar\Tests\Identity\Fixtures\Domains\Records;

use function Tinvar\Tests\Identity\Fixtures\Domains\failure;
use function Tinvar\Tests\Identity\Fixtures\Domains\verification;

use const Tinvar\Tests\Identity\Fixtures\Domains\TARGET;

require_once __DIR__ . '/Fixtures/domains.php';

const TOKEN = 'tls-ask-token-0123456789abcdef0123456789';

/** The waits an ask makes for another's check, kept rather than slept. */
final class Waits
{
    /** @var list<float> */
    public array $slept = [];

    /** @var (\Closure(int): void)|null run after each wait, with how many there have been */
    public ?\Closure $then = null;

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
        if ($this->then !== null) {
            ($this->then)(\count($this->slept));
        }
    }
}

/**
 * A store that counts every window spent, as ApcuThrottleStore does after
 * a wipe when it fails closed.
 */
final class SpentStore implements ThrottleStore
{
    public int $released = 0;

    public function increment(string $key, int $window): array
    {
        return [ApcuThrottleStore::LOST + 1, PHP_INT_MAX];
    }

    public function release(string $key, int $window, int $hits = 1): void
    {
        $this->released++;
    }
}

/**
 * @param array<string, string|list<string>|DnsLookupFailed> $dns
 * @return array{TlsAskEndpoint, Records, Dns, Clock, ArrayThrottleStore, Waits}
 */
function ask(array $dns = [], array $active = ['shop.acme.example'], array $inactive = [], ?ThrottleStore $store = null): array
{
    [$verification, $records, $resolver, $clock] = verification($dns, $active);
    foreach ($inactive as $host) {
        $records->domains[$host] = new CustomDomain($host, false);
    }
    $realms = new Realms(
        Realm::platform('https://platform.example'),
        [Realm::platform('https://admin.platform.example', panel: 'admin')],
        // The application's lookup: a tenant's subdomain of the
        // platform's domain, and its custom domains from its records.
        static fn (string $host): ?Realm => match (true) {
            $host === 'acme.platform.example' => Realm::platform('https://acme.platform.example', 'acme', 'customer'),
            ($records->domains[$host] ?? null)?->active === true => Realm::domain("https://{$host}", 'acme', 'customer'),
            default => null,
        },
        ['admin'],
    );

    // Claims on the records' clock, as a store shared by every worker is
    // on the one every server keeps.
    $claims = new ArrayThrottleStore(static fn (): int => $clock->now->getTimestamp());
    $waits = new Waits();
    $endpoint = new TlsAskEndpoint($realms, $records, $verification, $store ?? $claims, new ProbeAccess(token: TOKEN), $waits->sleep(...));

    return [$endpoint, $records, $resolver, $clock, $claims, $waits];
}

/** @param array<string, string> $headers */
function asking(string $domain, string $address = '127.0.0.1', array $headers = [], string $method = 'GET'): Request
{
    return new Request($method, TlsAskEndpoint::PATH, $headers, ['domain' => $domain], remoteAddress: $address);
}

describe('the TLS ask endpoint', function () {
    it('answers only callers ProbeAccess lets in, and finds nothing for the rest', function () {
        [$endpoint, $records, $dns] = ask(['shop.acme.example' => TARGET]);

        $outside = $endpoint->handle(asking('shop.acme.example', '198.51.100.9'));
        $rebound = $endpoint->handle(asking('shop.acme.example', '127.0.0.1', ['host' => 'rebound.attacker.example']));
        $proxied = $endpoint->handle(asking('shop.acme.example', '127.0.0.1', ['x-forwarded-for' => '198.51.100.9']));

        expect([$outside->status, $rebound->status, $proxied->status])->toBe([404, 404, 404])
            ->and($outside->body())->toBe('Not found.')
            ->and($records->found)->toBe([])
            ->and($dns->asked)->toBe([])
            ->and($endpoint->handle(asking('shop.acme.example', '198.51.100.9', ['authorization' => 'Bearer ' . TOKEN]))->status)->toBe(200);
    });

    it('answers 200 for a custom domain held active that verifies now, and records the check', function () {
        [$endpoint, $records, $dns, $clock] = ask(['shop.acme.example' => TARGET]);

        $response = $endpoint->handle(asking('Shop.ACME.example.'));

        expect($response->status)->toBe(200)
            ->and($response->header('cache-control'))->toBe('no-store')
            ->and($dns->asked)->toBe(['shop.acme.example'])
            ->and($records->domains['shop.acme.example']->checkedAt)->toEqual($clock->now);
    });

    it('answers 404 for a held domain pointing elsewhere, and deactivates it by the asking', function () {
        [$endpoint, $records] = ask(['shop.acme.example' => ['198.51.100.4']]);

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(404)
            ->and($records->domains['shop.acme.example']->active)->toBeFalse()
            ->and($endpoint->handle(asking('shop.acme.example'))->status)->toBe(404);
    });

    // Not an oracle: what the caller learns about a name it chose is
    // whether the application holds it, never what its DNS says.
    it('never resolves a name that is not in the records, nor one held inactive', function (string $domain) {
        [$endpoint, , $dns] = ask(['elsewhere.example' => ['203.0.113.7'], 'old.acme.example' => TARGET], inactive: ['old.acme.example']);

        expect($endpoint->handle(asking($domain))->status)->toBe(404)
            ->and($dns->asked)->toBe([]);
    })->with(['elsewhere.example', 'old.acme.example', 'nobody.platform.example']);

    it('answers 200 for the platform\'s own hosts with no lookup', function (string $domain) {
        [$endpoint, $records, $dns] = ask();

        expect($endpoint->handle(asking($domain))->status)->toBe(200)
            ->and($dns->asked)->toBe([])
            ->and($records->found)->toBe([]);
    })->with(['platform.example', 'admin.platform.example', 'acme.platform.example', 'PLATFORM.example.']);

    it('refuses what Tenancy\\Host does not read as a host name', function (mixed $domain) {
        [$endpoint, $records, $dns] = ask(['shop.acme.example' => TARGET]);
        $request = new Request('GET', TlsAskEndpoint::PATH, [], $domain === null ? [] : ['domain' => $domain], remoteAddress: '127.0.0.1');

        expect($endpoint->handle($request)->status)->toBe(404)
            ->and($records->found)->toBe([])
            ->and($dns->asked)->toBe([]);
    })->with([
        'none' => [null],
        'empty' => [''],
        'an address' => ['203.0.113.7'],
        'an IPv6 literal' => ['[2001:db8::1]'],
        'a stray character' => ['shop_acme.example'],
        'two of them' => [['shop.acme.example', 'platform.example']],
    ]);

    it('answers GET and HEAD alone', function () {
        [$endpoint] = ask(['shop.acme.example' => TARGET]);

        expect($endpoint->handle(asking('shop.acme.example', method: 'HEAD'))->status)->toBe(200)
            ->and($endpoint->handle(asking('shop.acme.example', method: 'POST'))->status)->toBe(405)
            ->and($endpoint->handle(asking('shop.acme.example', method: 'POST'))->header('allow'))->toBe('GET, HEAD');
    });

    // Caddy asks at every handshake for a name it holds no certificate for:
    // a stream of them naming a domain whose DNS times out would hold a
    // worker for a full check each.
    it('refuses a domain whose last check failed under a minute ago without resolving it again', function () {
        [$endpoint, $records, $dns, $clock] = ask(['shop.acme.example' => failure()]);

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(404)
            ->and($records->domains['shop.acme.example']->failingSince)->not->toBeNull();

        $clock->advance('+59 seconds');
        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(404)
            ->and($dns->asked)->toBe(['shop.acme.example']);

        $clock->advance('+1 second');
        $endpoint->handle(asking('shop.acme.example'));
        expect($dns->asked)->toBe(['shop.acme.example', 'shop.acme.example']);
    });

    // And one that passed: Caddy asks again at each handshake while it holds
    // no certificate -- one whose issuance keeps failing, say, which whoever
    // controls the domain's DNS can arrange with a CAA record -- and each
    // ask was a full check. DNS that answers just inside the check's time,
    // and is never cached, then held a worker for most of four seconds per
    // handshake.
    it('answers a domain that passed under a minute ago from that check, without resolving it again', function () {
        [$endpoint, $records, $dns, $clock] = ask(['shop.acme.example' => TARGET]);

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200);
        $checked = $records->domains['shop.acme.example']->checkedAt;

        $clock->advance('+59 seconds');
        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($dns->asked)->toBe(['shop.acme.example'])
            ->and($records->domains['shop.acme.example']->checkedAt)->toEqual($checked);

        $clock->advance('+1 second');
        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($dns->asked)->toBe(['shop.acme.example', 'shop.acme.example']);
    });

    it('checks again a domain whose pass is recorded later than now', function () {
        [$endpoint, $records, $dns, $clock] = ask(['shop.acme.example' => TARGET]);
        $records->passed('shop.acme.example', $clock->now->modify('+1 hour'));

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($dns->asked)->toBe(['shop.acme.example']);
    });

    // A burst of handshakes naming a domain no check has recorded in the
    // last minute: each ask made a full check of its own, a worker apiece
    // for as long as DNS took. One checks now; the rest look nothing up.
    // And none is refused for the contention alone, which whoever opens
    // handshakes could cause: a renewal would be lost to it.
    it('allows an ask that arrives during another\'s check of a domain whose last check passed, with no lookup of its own', function () {
        [$endpoint, $records, $dns, $clock] = ask(['shop.acme.example' => TARGET]);
        $records->passed('shop.acme.example', $clock->now->modify('-2 days'));
        $during = null;
        $dns->meanwhile = static function () use ($endpoint, &$during): void {
            $during = $endpoint->handle(asking('shop.acme.example'));
        };

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($during?->status)->toBe(200)
            ->and($dns->asked)->toBe(['shop.acme.example']);
    });

    it('refuses one during another\'s check of a domain whose last check failed, with no lookup of its own', function () {
        [$endpoint, $records, $dns, $clock] = ask(['shop.acme.example' => failure()]);
        $records->failed('shop.acme.example', $clock->now->modify('-2 minutes'), $clock->now->modify('-1 hour'));
        $during = null;
        $dns->meanwhile = static function () use ($endpoint, &$during): void {
            $during = $endpoint->handle(asking('shop.acme.example'));
        };

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(404)
            ->and($during?->status)->toBe(404)
            ->and($dns->asked)->toBe(['shop.acme.example']);
    });

    it('has an ask for a domain never checked wait for the check under way, and follow what it records', function (bool $passes) {
        [$endpoint, $records, $dns, $clock, $claims, $waits] = ask(['shop.acme.example' => TARGET]);
        // Another worker's check, which records its outcome a few steps in.
        $claims->increment(TlsAskEndpoint::CLAIM . 'shop.acme.example', TlsAskEndpoint::CHECK_WINDOW);
        $waits->then = static function (int $waited) use ($records, $clock, $passes): void {
            if ($waited === 3) {
                $passes
                    ? $records->passed('shop.acme.example', $clock->now)
                    : $records->failed('shop.acme.example', $clock->now, $clock->now);
            }
        };

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe($passes ? 200 : 404)
            ->and($waits->slept)->toBe([TlsAskEndpoint::WAIT_STEP, TlsAskEndpoint::WAIT_STEP, TlsAskEndpoint::WAIT_STEP])
            ->and($dns->asked)->toBe([]);
    })->with(['the check passes' => [true], 'the check fails' => [false]]);

    it('refuses a domain never checked once its wait ends with nothing recorded, and checks it once the claim has gone', function () {
        [$endpoint, , $dns, $clock, $claims, $waits] = ask(['shop.acme.example' => TARGET]);
        $claims->increment(TlsAskEndpoint::CLAIM . 'shop.acme.example', TlsAskEndpoint::CHECK_WINDOW);

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(404)
            ->and($dns->asked)->toBe([])
            // No longer than a check may take: the verifier's four seconds.
            ->and(array_sum($waits->slept))->toBeGreaterThanOrEqual(4.0 - TlsAskEndpoint::WAIT_STEP)
            ->and(array_sum($waits->slept))->toBeLessThan(4.0 + TlsAskEndpoint::WAIT_STEP);

        // Its worker killed mid-check: the claim lasts its window, no longer.
        $clock->advance('+' . TlsAskEndpoint::CHECK_WINDOW . ' seconds');
        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($dns->asked)->toBe(['shop.acme.example']);
    });

    // ApcuThrottleStore, failing closed after a wipe, counts every window
    // spent: no ask ever got a count of one, and every one would have been
    // answered as if another's check were under way. Nobody's is.
    it('takes a window the store counts as spent for no claim, not for another\'s check', function () {
        $store = new SpentStore();
        [$endpoint, $records, $dns, $clock, , $waits] = ask(
            ['shop.acme.example' => TARGET, 'new.acme.example' => TARGET],
            ['shop.acme.example', 'new.acme.example'],
            store: $store,
        );
        $records->passed('shop.acme.example', $clock->now->modify('-2 days'));

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            // Never checked, and no check under way to wait for.
            ->and($endpoint->handle(asking('new.acme.example'))->status)->toBe(404)
            ->and($waits->slept)->toBe([])
            ->and($dns->asked)->toBe([])
            ->and($store->released)->toBe(0);
    });

    it('lets its own claim go however the check ends', function () {
        [$endpoint, $records, $dns] = ask(['shop.acme.example' => TARGET]);
        $records->broken['shop.acme.example'] = true;

        expect(static fn () => $endpoint->handle(asking('shop.acme.example')))->toThrow(\RuntimeException::class);

        unset($records->broken['shop.acme.example']);
        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($dns->asked)->toBe(['shop.acme.example', 'shop.acme.example']);
    });

    // A check that outlasts its claim's window: by the time it ends, the
    // store's current window is the next one, where another worker may have
    // claimed the domain. Taken back from there, that claim was gone, and
    // the next ask checked beside the other worker's check.
    it('leaves another worker\'s claim alone when its own check outlasts its window', function () {
        [$endpoint, , $dns, $clock, $claims] = ask(['shop.acme.example' => TARGET]);
        $other = null;
        $dns->meanwhile = static function () use ($clock, $claims, &$other): void {
            $clock->advance('+' . TlsAskEndpoint::CHECK_WINDOW . ' seconds');
            $other = $claims->increment(TlsAskEndpoint::CLAIM . 'shop.acme.example', TlsAskEndpoint::CHECK_WINDOW)[0];
        };

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($other)->toBe(1)
            // The other worker's hit still there: a third ask counts two.
            ->and($claims->increment(TlsAskEndpoint::CLAIM . 'shop.acme.example', TlsAskEndpoint::CHECK_WINDOW)[0])->toBe(2);
    });

    it('takes back only its own hit, leaving those of the asks that came during its check', function () {
        [$endpoint, , $dns, , $claims] = ask(['shop.acme.example' => TARGET]);
        $dns->meanwhile = static function () use ($endpoint): void {
            $endpoint->handle(asking('shop.acme.example'));
        };

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($claims->increment(TlsAskEndpoint::CLAIM . 'shop.acme.example', TlsAskEndpoint::CHECK_WINDOW)[0])->toBe(2);
    });

    // Read before the other's check was recorded, claimed after it let go:
    // the claim alone would have let it check the domain a second time.
    it('answers from a check recorded between its first read and its claim', function () {
        [$endpoint, $records, $dns] = ask(['shop.acme.example' => TARGET]);
        $other = null;
        $records->meanwhile = static function () use ($endpoint, &$other): void {
            $other = $endpoint->handle(asking('shop.acme.example'));
        };

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200)
            ->and($other?->status)->toBe(200)
            ->and($dns->asked)->toBe(['shop.acme.example']);
    });

    it('still checks a recently passed domain the application has since deactivated as inactive', function () {
        [$endpoint, $records, $dns] = ask(['shop.acme.example' => TARGET]);
        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(200);

        $records->deactivate('shop.acme.example', 'moved', new \DateTimeImmutable());

        expect($endpoint->handle(asking('shop.acme.example'))->status)->toBe(404)
            ->and($dns->asked)->toBe(['shop.acme.example']);
    });
});

/** Counts how often the application's own stack ran: a session would start here. */
final class StartSession implements Middleware
{
    public static int $runs = 0;

    public function handle(Request $request, \Closure $next): Response
    {
        self::$runs++;

        return $next($request);
    }
}

it('is answered by the Kernel ahead of the application\'s stack, counted by endpoint and never by domain', function () {
    [$endpoint] = ask(['shop.acme.example' => TARGET]);
    $sink = new MemorySink();
    $telemetry = new Telemetry(new Logger($sink, level: 'debug'), new Metrics(new ArrayMetricsStore()));
    StartSession::$runs = 0;
    $kernel = new Kernel(
        orm: new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test')),
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [StartSession::class],
        telemetry: $telemetry,
        tlsAsk: $endpoint,
    );
    $respond = static fn (LoadedRoute $loaded, Request $request): Response => Response::make(200);

    $allowed = $kernel->handle(asking('shop.acme.example', headers: ['host' => 'localhost']), $respond);
    $refused = $kernel->handle(asking('unknown.example', headers: ['host' => 'localhost']), $respond);
    $outside = $kernel->handle(asking('shop.acme.example', '198.51.100.9', ['accept' => 'text/html']), $respond);

    $exposition = (string) $telemetry->metrics?->exposition();
    expect([$allowed->status, $refused->status, $outside->status])->toBe([200, 404, 404])
        ->and($outside->body())->toBe('Not found.')
        ->and(StartSession::$runs)->toBe(0)
        ->and($kernel->answersProbe(asking('shop.acme.example')))->toBeTrue()
        ->and($exposition)->toContain('tinvar_http_requests_total{method="GET",route="_tls_ask",status="200"} 1')
        ->and($exposition)->toContain('tinvar_http_requests_total{method="GET",route="_tls_ask",status="404"} 2')
        ->and($exposition)->not->toContain('acme')
        ->and($exposition)->not->toContain('unknown.example')
        ->and(json_encode($sink->records(), JSON_THROW_ON_ERROR))->not->toContain('acme.example')
        ->and(json_encode($sink->records(), JSON_THROW_ON_ERROR))->not->toContain('unknown.example');
});
