<?php

declare(strict_types=1);

/**
 * An entry point's #[TenantFrom], run through whatever tenancy is
 * registered -- or refused with none (ADR 0056).
 *
 * The framework knows no tenant source: tinvar/tenancy reads them, through
 * TenantResolver. These tests run with no tenancy installed. A resolver of
 * their own stands in, and is held to what no resolver may do: name no
 * tenant, or move a request already running as a tenant to another.
 */

namespace Tinvar\Tests\Http\EnterTenant;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\EnterTenant;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\TenantResolver;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Webhooks\ArrayNonceStore;
use Tinvar\Webhooks\DerivedSecrets;
use Tinvar\Webhooks\WebhookEndpoint;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/tenancy.php';

/** A class an entry point names as its tenant source: what it is is the resolver's to say. */
final class FromHeader {}

#[Page('/scoped', view: 'scoped', name: 'scoped'), TenantFrom(FromHeader::class)]
final class ScopedPage {}

/** A resolver answering whatever it is told, as a careless one might. */
final class Answers implements TenantResolver
{
    /** @var list<string> */
    public array $checked = [];

    public function __construct(private readonly string $answer) {}

    public function check(string $source): void
    {
        $this->checked[] = $source;
    }

    public function resolve(Orm $orm, string $source, Request $request): string
    {
        return $this->answer;
    }
}

function orm(bool $tenancy = true): Orm
{
    return new Orm(PdoConnection::sqlite(':memory:', 'enter'), new IdentityMap('enter'), $tenancy ? Tenancy::flat() : null);
}

function entry(Orm $orm, ?TenantResolver $tenants): EntryContext
{
    return new EntryContext($orm, static fn (string $class): object => new $class(), tenants: $tenants);
}

/** The tenant the rest of the request runs as, through the stack an entry point declaring FromHeader gets. */
function enter(Orm $orm, TenantResolver $tenants): string
{
    $stack = entry($orm, $tenants)->stackFor(FromHeader::class);

    return $stack[0]->handle(new Request('GET', '/scoped'), static fn (): Response => Response::make(200, $orm->tenant()))->body();
}

describe('with no tenancy registered', function () {
    it('refuses an entry point declaring a tenant source when it is registered', function () {
        $routes = new RouteRegistry();
        $routes->registerAll([ScopedPage::class]);

        expect(fn () => entry(orm(), null)->check($routes, new ActionRegistry(), new QueryRegistry()))
            ->toThrow(\InvalidArgumentException::class, 'no tenancy is registered');
    });

    it('refuses one reached anyway, rather than run it as no tenant', function () {
        expect(fn () => entry(orm(), null)->stackFor(FromHeader::class))->toThrow(\LogicException::class, 'no tenancy is registered')
            ->and(fn () => entry(orm(), null)->tenantFor(FromHeader::class, new Request('GET', '/')))->toThrow(\LogicException::class, 'no tenancy is registered');
    });

    it('runs an entry point that declares none as before', function () {
        expect(entry(orm(false), null)->stackFor(null))->toBe([])
            ->and(entry(orm(false), null)->tenantFor(null, new Request('GET', '/')))->toBe('');
    });
});

// Half registered is neither: entry points would run as tenants the Orm
// reads no rows of, under tokens that name none of them.
it('is refused given to the framework and not to the Orm', function () {
    expect(fn () => new WebhookEndpoint([], orm(false), static fn (string $c): object => new $c(), new DerivedSecrets(new Signer('k', DerivedSecrets::PURPOSE)), new ArrayNonceStore(), tenants: new Answers('acme')))
        ->toThrow(\InvalidArgumentException::class, 'its Orm none');
});

describe('with tenancy registered', function () {
    it('asks it to check every source declared, when the entry point is registered', function () {
        $routes = new RouteRegistry();
        $routes->registerAll([ScopedPage::class]);
        $tenants = new Answers('acme');

        entry(orm(), $tenants)->check($routes, new ActionRegistry(), new QueryRegistry());

        expect($tenants->checked)->toBe([FromHeader::class]);
    });

    it('runs the entry point as the tenant it answers', function () {
        expect(enter(orm(), new Answers('acme')))->toBe('acme');
    });

    it('refuses an answer that names no tenant', function () {
        expect(fn () => enter(orm(), new Answers('')))->toThrow(HttpException::class, 'no tenant');
    });

    // The framework's own check, so it holds with any resolver: a request
    // running as the session's tenant is never moved to another by what it
    // says about itself.
    it('never moves a request already running as a tenant to another', function () {
        $orm = orm();

        expect(fn () => $orm->actAs('acme', static fn (): string => enter($orm, new Answers('globex'))))
            ->toThrow(HttpException::class, 'other than the one it is running as')
            ->and($orm->actAs('acme', static fn (): string => enter($orm, new Answers('acme'))))->toBe('acme');

        try {
            $orm->actAs('acme', static fn (): string => EnterTenant::resolve($orm, new Answers('globex'), FromHeader::class, new Request('GET', '/')));
        } catch (HttpException $e) {
            expect($e->status)->toBe(404)->and($e->code_)->toBe('tenant_unknown');
        }
    });
});
