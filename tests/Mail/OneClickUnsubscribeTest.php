<?php

declare(strict_types=1);

/**
 * One-click unsubscribe (ADR 0048, RFC 8058): a signed link for one list,
 * one reader and one tenant, and the endpoint a mail provider POSTs it to --
 * with no session, so the link is all that lets it in.
 */

namespace Tinvar\Tests\Mail\OneClickUnsubscribe;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Mail\UnsubscribeEndpoint;
use Tinvar\Mail\Unsubscriber;
use Tinvar\Mail\UnsubscribeLinks;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\SignedToken;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;

const KEY = 'unsubscribe-test-application-key-0123456789';
const ORIGIN = 'https://shop.example';
const NOW = 1_800_000_000;

/** Records who was unsubscribed from what, and in which tenant. */
final class Recorder implements Unsubscriber
{
    /** @var list<array{string, string, string}> */
    public array $calls = [];

    public function __construct(private readonly Orm $orm) {}

    public function unsubscribe(string $list, string $recipient): void
    {
        $this->calls[] = [$list, $recipient, $this->orm->tenant()];
    }
}

function links(int $now = NOW, string $key = KEY): UnsubscribeLinks
{
    return UnsubscribeLinks::fromKey($key, ORIGIN, static fn (): int => $now);
}

function tokenOf(string $url): string
{
    return substr($url, \strlen(ORIGIN . UnsubscribeLinks::PATH . '/'));
}

describe('a link', function () {
    it('is the endpoint\'s, on the canonical origin, with a token naming the list, the reader and the tenant', function () {
        $url = links()->url('digest', 'c-42', 'tenant-7');

        expect($url)->toStartWith(ORIGIN . UnsubscribeLinks::PATH . '/v1.')
            ->and(links()->verify(tokenOf($url)))->toBe(['list' => 'digest', 'recipient' => 'c-42', 'tenant' => 'tenant-7']);
    });

    it('keeps working long after the email went, and stops after its lifetime', function () {
        $token = tokenOf(links()->url('digest', 'c-42'));

        expect(links(NOW + 30 * 86400)->verify($token))->not->toBeNull()
            ->and(links(NOW + UnsubscribeLinks::LIFETIME)->verify($token))->toBeNull();
    });

    it('is refused when another key made it, it was altered, or it is a token of another kind', function () {
        $token = tokenOf(links()->url('digest', 'c-42'));
        [$version, $payload, $mac] = explode('.', $token);
        $claims = json_decode((string) Signer::decode($payload), true);
        $altered = $version . '.' . Signer::encode((string) json_encode([...$claims, 'p' => 'c-43'])) . '.' . $mac;
        // Signed with the same key, for another purpose.
        $route = SignedToken::encode(new Signer(KEY, UnsubscribeLinks::PURPOSE), 'route-token', $claims);

        expect(links(key: KEY . 'x')->verify($token))->toBeNull()
            ->and(links()->verify($altered))->toBeNull()
            ->and(links()->verify($route))->toBeNull()
            ->and(links()->verify('not a token'))->toBeNull();
    });

    it('is built only on an https origin, never a path or a request\'s host', function (string $origin) {
        expect(static fn () => UnsubscribeLinks::fromKey(KEY, $origin))->toThrow(\InvalidArgumentException::class);
    })->with([
        ['http://shop.example'],
        ['https://shop.example/account'],
        ['https://shop.example?x=1'],
        ['https://user@shop.example'],
        ['shop.example'],
    ]);

    it('names a list and a reader it can carry', function () {
        expect(static fn () => links()->url('Weekly Digest', 'c-42'))->toThrow(\InvalidArgumentException::class, 'mailing list')
            ->and(static fn () => links()->url('digest', ''))->toThrow(\InvalidArgumentException::class, 'recipient')
            ->and(static fn () => links()->url('digest', "c-42\n"))->toThrow(\InvalidArgumentException::class, 'recipient');
    });
});

/** @return array{Kernel, Recorder} */
function kernel(): array
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
    $recorder = new Recorder($orm);

    return [new Kernel(
        orm: $orm,
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        gate: new AllowAllGate(),
        // Every POST anywhere else must carry its session's CSRF token.
        csrf: new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), static fn (Request $r): string => $r->cookie('session') ?? ''),
        unsubscribe: new UnsubscribeEndpoint(links(time()), $recorder, $orm),
    ), $recorder];
}

/** A provider's one-click POST: the RFC 8058 body, no cookie, no CSRF token. */
function oneClick(string $path, string $body = 'List-Unsubscribe=One-Click', string $method = 'POST'): Request
{
    parse_str($body, $fields);

    return new Request($method, $path, ['content-type' => 'application/x-www-form-urlencoded'], body: $fields);
}

// Through the Kernel, which mounts the endpoint: a test of modules wired
// together, run in the whole suite only (bin/module-alone, ADR 0114).
describe('the endpoint', function () {
    it('unsubscribes the reader a link names, in its tenant, from a POST with no session or CSRF token', function () {
        [$kernel, $recorder] = kernel();
        $path = UnsubscribeLinks::PATH . '/' . tokenOf(links(time())->url('digest', 'c-42', 'tenant-7'));

        $response = $kernel->handle(oneClick($path), static fn (): Response => Response::make(200), 'List-Unsubscribe=One-Click');

        expect($response->status)->toBe(200)
            ->and($response->header('location'))->toBeNull()
            ->and($response->header('cache-control'))->toBe('no-store')
            ->and($response->cookies())->toBe([])
            ->and($recorder->calls)->toBe([['digest', 'c-42', 'tenant-7']]);
    });

    // A link scanner GETs every link in a mailbox: it must unsubscribe no one.
    it('unsubscribes no one from a GET, a POST without the one-click body, or a link it did not make', function () {
        [$kernel, $recorder] = kernel();
        $token = tokenOf(links(time())->url('digest', 'c-42'));
        $forged = tokenOf(links(time(), KEY . 'x')->url('digest', 'c-42'));
        $respond = static fn (): Response => Response::make(200);

        $get = $kernel->handle(oneClick(UnsubscribeLinks::PATH . "/{$token}", '', 'GET'), $respond);
        $bare = $kernel->handle(oneClick(UnsubscribeLinks::PATH . "/{$token}", 'unsubscribe=1'), $respond, 'unsubscribe=1');
        $wrong = $kernel->handle(oneClick(UnsubscribeLinks::PATH . "/{$forged}"), $respond, 'List-Unsubscribe=One-Click');
        $junk = $kernel->handle(oneClick(UnsubscribeLinks::PATH . '/nothing'), $respond, 'List-Unsubscribe=One-Click');

        expect([$get->status, $bare->status, $wrong->status, $junk->status])->toBe([405, 400, 404, 404])
            ->and($get->header('allow'))->toBe('POST')
            ->and($recorder->calls)->toBe([]);
    });

    it('reads the one-click body the adapter left unparsed', function () {
        [$kernel, $recorder] = kernel();
        $path = UnsubscribeLinks::PATH . '/' . tokenOf(links(time())->url('digest', 'c-42'));

        $response = $kernel->handle(
            new Request('POST', $path, ['content-type' => 'application/x-www-form-urlencoded']),
            static fn (): Response => Response::make(200),
            'List-Unsubscribe=One-Click',
        );

        expect($response->status)->toBe(200)
            ->and($recorder->calls)->toBe([['digest', 'c-42', '']]);
    });

    it('is not mounted without one', function () {
        $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
        $kernel = new Kernel(
            orm: $orm,
            routes: new RouteRegistry(),
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: new AuthContext(),
            policies: new PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            gate: new AllowAllGate(),
        );
        $path = UnsubscribeLinks::PATH . '/' . tokenOf(links(time())->url('digest', 'c-42'));

        expect($kernel->handle(oneClick($path), static fn (): Response => Response::make(200), 'List-Unsubscribe=One-Click')->status)
            ->toBe(404);
    });
})->group('wired');
