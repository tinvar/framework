<?php

declare(strict_types=1);

use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\AuthorizationException;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Auth\Fixtures as A;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Orm\Fixtures\Unit;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';
require_once __DIR__ . '/Fixtures/policies.php';

function gateFor(?A\User $user): array
{
    $auth = new AuthContext();
    $auth->login($user);

    $policies = (new PolicyRegistry())->registerAll([
        Rack::class => A\RackPolicy::class,
        Site::class => A\SitePolicy::class,
        Unit::class => A\EmptyPolicy::class,
    ]);

    return [new PolicyGate($policies, $auth), $auth];
}

describe('the policy gate', function () {
    it('asks the policy registered for the subject', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        [$allowed] = gateFor(new A\User('u1', ownedRackNames: ['R-01']));
        [$denied] = gateFor(new A\User('u2', ownedRackNames: ['R-99']));

        $check = new Authorize('view', 'rack');

        expect($allowed->allows($check, ['rack' => $rack]))->toBeTrue()
            ->and($denied->allows($check, ['rack' => $rack]))->toBeFalse();
    });

    it('denies an anonymous caller when the policy says so', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        [$gate] = gateFor(null);

        expect($gate->allows(new Authorize('view', 'rack'), ['rack' => $rack]))->toBeFalse();
    });

    it('accepts a class as the subject, for a viewAny-style check', function () {
        [$gate] = gateFor(new A\User('u1'));

        // The class comes from the attribute, so no argument is needed.
        expect($gate->allows(new Authorize('viewAny', Rack::class), []))->toBeTrue();
    });

    it('lets a before() hook answer for every ability', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $site = $orm->find(Site::class, $fixture->ids['sites'][0]);
        $check = new Authorize('view', 'site');

        [$admin] = gateFor(new A\User('u1', isAdmin: true));
        [$ordinary] = gateFor(new A\User('u2'));

        // SitePolicy::view() always returns false; only before() can allow.
        expect($admin->allows($check, ['site' => $site]))->toBeTrue()
            ->and($ordinary->allows($check, ['site' => $site]))->toBeFalse();
    });

    // Every path either returns a decision or throws. Nothing defaults to allow.
    it('throws rather than guessing when there is no subject', function () {
        [$gate] = gateFor(new A\User('u1'));

        expect(fn () => $gate->allows(new Authorize('view', null), []))
            ->toThrow(AuthorizationException::class, 'no subject to check');
    });

    // The gate derives the subject from the attribute. A caller-supplied key
    // must not be able to point it at a different record.
    it('refuses a subject that was never resolved to a model', function () {
        [$gate] = gateFor(new A\User('u1'));

        expect(fn () => $gate->allows(new Authorize('view', 'rack'), ['rack' => 'some-id-string']))
            ->toThrow(AuthorizationException::class, 'needs a resolved model');
    });

    it('ignores a subject key the caller invents', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        [$gate] = gateFor(new A\User('u1', ownedRackNames: ['R-01']));

        // 'subject' means nothing; 'rack' is what the attribute names.
        expect(fn () => $gate->allows(new Authorize('view', 'rack'), ['subject' => $rack]))
            ->toThrow(AuthorizationException::class, 'needs a resolved model');
    });

    it('throws when no policy is registered for the subject', function () {
        [$gate] = gateFor(new A\User('u1'));

        expect(fn () => $gate->allows(new Authorize('view', Script::class), []))
            ->toThrow(AuthorizationException::class, 'No policy is registered');
    });

    it('throws when the policy has no such ability', function () {
        [$gate] = gateFor(new A\User('u1'));

        expect(fn () => $gate->allows(new Authorize('destroy', Unit::class), []))
            ->toThrow(AuthorizationException::class, "has no 'destroy' method");
    });

    it('resolves a policy registered for a parent class', function () {
        $policies = (new PolicyRegistry())->register(Tinvar\Orm\Model::class, A\RackPolicy::class);

        expect($policies->hasFor(Rack::class))->toBeTrue()
            ->and($policies->for(Rack::class))->toBeInstanceOf(A\RackPolicy::class);
    });
});

describe('the auth context', function () {
    it('restores the previous user after actingAs, even on a throw', function () {
        $auth = new AuthContext();
        $auth->login(new A\User('original'));

        try {
            $auth->actingAs(new A\User('other'), static fn (): never => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        expect($auth->id())->toBe('original');
    });

    it('resets, because a worker outlives the request', function () {
        $auth = new AuthContext();
        $auth->login(new A\User('u1'));
        $auth->reset();

        expect($auth->check())->toBeFalse()->and($auth->user())->toBeNull();
    });
});

it('denies a real request through the router with a real policy', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $registry = new RouteRegistry();
    $registry->register(A\GuardedPage::class);

    [$gate] = gateFor(new A\User('u1', ownedRackNames: ['R-02']));
    $runner = new LoaderRunner($orm, static fn (string $c): object => new $c(), $gate);
    $router = new Router($registry, $runner, static fn (string $c): Middleware => new $c());

    $respond = static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'ok');

    // R-01 is not owned; R-02 is.
    expect(fn () => $router->handle(Request::get("/good/{$fixture->ids['racks'][0]}"), $respond))
        ->toThrow(ForbiddenException::class);

    expect($router->handle(Request::get("/good/{$fixture->ids['racks'][1]}"), $respond)->status)->toBe(200);
});

describe('build rules', function () {
    it('passes an entry point whose model inputs are all named', function () {
        expect((new BuildRules())->check([A\GuardedPage::class]))->toBe([]);
    });

    it('fails an entry point with an unguarded model input', function () {
        $violations = (new BuildRules())->check([A\UnguardedPage::class]);

        expect($violations)->toHaveCount(1)
            ->and($violations[0]->rule)->toBe(BuildRules::AUTHORIZE_EVERY_MODEL_INPUT)
            ->and((string) $violations[0])->toContain('UnguardedPage::__invoke($rack)');
    });

    // "This includes second and third model arguments."
    it('fails a second model argument that no check names', function () {
        $violations = (new BuildRules())->check([A\PartiallyGuardedPage::class]);

        expect($violations)->toHaveCount(1)
            ->and((string) $violations[0])->toContain('$site');
    });

    it('accepts a declared, reasoned opt-out', function () {
        expect((new BuildRules())->check([A\ExemptPage::class]))->toBe([]);
    });

    it('checks queries and action methods, not just pages', function () {
        $where = array_map(
            static fn ($v): string => $v->where,
            (new BuildRules())->check([A\UnguardedQuery::class, A\Actions::class]),
        );

        expect($where)->toContain(A\UnguardedQuery::class . '::__invoke($unit)')
            ->and($where)->toContain(A\Actions::class . '::move($unit)')
            ->and($where)->not->toContain(A\Actions::class . '::rename($rack)');
    });

    // The failure the rule exists to catch is forgetting the attribute, so
    // routability must never depend on the attribute being there.
    it('flags an #[Action] that declares nothing at all', function () {
        $where = array_map(
            static fn ($v): string => $v->where,
            (new BuildRules())->check([A\Actions::class]),
        );

        expect($where)->toContain(A\Actions::class . '::destroy($rack)');
    });

    it('flags a static entry point rather than skipping it', function () {
        $rules = array_map(
            static fn ($v): string => $v->rule,
            array_values(array_filter(
                (new BuildRules())->check([A\Actions::class]),
                static fn ($v): bool => str_contains($v->where, 'archive'),
            )),
        );

        expect($rules)->toBe([BuildRules::ENTRY_POINT_MUST_NOT_BE_STATIC]);
    });

    it('does not let a class-level check cover a later method', function () {
        $where = array_map(
            static fn ($v): string => $v->where,
            (new BuildRules())->check([A\BoardPage::class]),
        );

        expect($where)->toContain(A\BoardPage::class . '::nuke($rack)')
            ->and($where)->not->toContain(A\BoardPage::class . '::__invoke($rack)');
    });

    it('does not let a class-level opt-out exempt a later method', function () {
        $where = array_map(
            static fn ($v): string => $v->where,
            (new BuildRules())->check([A\StatusBoardPage::class]),
        );

        expect($where)->toContain(A\StatusBoardPage::class . '::delete($rack)');
    });

    it('flags an #[Authorize] with no subject', function () {
        $violations = (new BuildRules())->check([A\SubjectlessPage::class]);

        expect($violations)->toHaveCount(1)
            ->and($violations[0]->rule)->toBe(BuildRules::AUTHORIZE_MUST_NAME_A_SUBJECT);
    });

    it('flags a check naming a parameter that is not model-typed', function () {
        $violations = (new BuildRules())->check([A\RawCheckPage::class]);

        expect($violations)->toHaveCount(1)
            ->and($violations[0]->rule)->toBe(BuildRules::AUTHORIZE_MUST_NAME_A_SUBJECT)
            ->and($violations[0]->message)->toContain('not model-typed');
    });

    it('flags a base-class-typed and a union-typed model input', function () {
        $where = array_map(
            static fn ($v): string => $v->where,
            (new BuildRules())->check([A\WideTypedPage::class]),
        );

        expect($where)->toContain(A\WideTypedPage::class . '::__invoke($thing)')
            ->and($where)->toContain(A\WideTypedPage::class . '::__invoke($other)');
    });

    describe('tenant-scoped ids must be typed', function () {
        $rules = fn (): BuildRules => new BuildRules([Script::class, Rack::class]);

        it('fails a bare string id naming a tenant-scoped model', function () use ($rules) {
            $violations = $rules()->check([], [A\BadIdRequest::class]);

            expect($violations)->toHaveCount(1)
                ->and($violations[0]->rule)->toBe(BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED)
                ->and((string) $violations[0])->toContain('$scriptId');
        });

        it('accepts the same field typed as the model', function () use ($rules) {
            expect($rules()->check([], [A\GoodIdRequest::class]))->toBe([]);
        });

        it('ignores an id naming a model that is not tenant-scoped', function () {
            // Rack carries no #[TenantScoped], so rackId is not flagged.
            expect((new BuildRules([Rack::class]))->check([], [A\BadIdRequest::class]))->toBe([]);
        });

        it('recurses into a nested Data object', function () use ($rules) {
            $violations = $rules()->check([], [A\OuterData::class]);

            expect($violations)->toHaveCount(1)
                ->and($violations[0]->where)->toContain('InnerIdData::$scriptId');
        });

        it('flags a plural list of ids', function () use ($rules) {
            expect($rules()->check([], [A\PluralIdsRequest::class]))->toHaveCount(1);
        });
    });

    it('assert() throws listing every violation at once', function () {
        expect(fn () => (new BuildRules([Script::class]))->assert(
            [A\UnguardedPage::class, A\PartiallyGuardedPage::class],
            [A\BadIdRequest::class],
        ))->toThrow(RuntimeException::class, 'Build rules failed');
    });
});
