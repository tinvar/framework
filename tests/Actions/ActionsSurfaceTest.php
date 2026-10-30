<?php

declare(strict_types=1);

namespace Tinvar\Tests\Actions\Surface;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\DenyGate;
use Tinvar\Auth\Gate;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Validator;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final class HelperMethodActions
{
    public function helper(): string
    {
        return 'not an action';
    }

    #[Action, NoAuthorization(reason: 'test')]
    public function run(): ActionResult
    {
        return ActionResult::ok($this->helper());
    }
}

final class StaticActions
{
    #[Action, NoAuthorization(reason: 'test')]
    public static function ping(): ActionResult
    {
        return ActionResult::ok();
    }
}

final class UnionParameterActions
{
    #[Action, NoAuthorization(reason: 'test')]
    public function at(int|string $when): ActionResult
    {
        return ActionResult::ok($when);
    }
}

final class ObjectParameterActions
{
    #[Action, NoAuthorization(reason: 'test')]
    public function at(object $when): ActionResult
    {
        return ActionResult::ok();
    }
}

/**
 * The class check beside each optional one decides a call that leaves the
 * site out. Without it, such a call is decided by nothing, and refused.
 */
final class OptionalSiteActions
{
    #[Action, Authorize('viewAny', Site::class), Authorize('view', 'site')]
    public function defaulted(?Site $site = null): ActionResult
    {
        return ActionResult::ok($site?->code ?? 'none');
    }

    #[Action, Authorize('viewAny', Site::class), Authorize('view', 'site')]
    public function nullable(?Site $site): ActionResult
    {
        return ActionResult::ok($site?->code ?? 'none');
    }
}

final class RequestActions
{
    #[Action, NoAuthorization(reason: 'test')]
    public function path(Request $request): ActionResult
    {
        return ActionResult::ok($request->path);
    }
}

final class ScalarActions
{
    #[Action, NoAuthorization(reason: 'test')]
    public function repeat(string $word, int $times = 1): ActionResult
    {
        return ActionResult::ok(str_repeat($word, $times));
    }
}

final readonly class NoFieldsRequest extends FormRequest {}

final class NoFieldsActions
{
    #[Action, NoAuthorization(reason: 'test')]
    public function submit(NoFieldsRequest $request): ActionResult
    {
        return ActionResult::ok($request::class);
    }
}

final class InjectedActions
{
    public function __construct(private readonly string $greeting) {}

    #[Action, NoAuthorization(reason: 'test')]
    public function greet(): ActionResult
    {
        return ActionResult::ok($this->greeting);
    }
}

#[Middleware('mw.web')]
final class MiddlewareActions
{
    #[Action, NoAuthorization(reason: 'test'), Middleware('mw.auth', 'mw.audit'), Middleware('mw.audit')]
    public function go(): ActionResult
    {
        return ActionResult::ok();
    }
}

/**
 * @param list<class-string> $holders
 * @return array{0: ActionRunner, 1: Fixture}
 */
function surfaceRunner(array $holders, ?Gate $gate = null): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $registry = new ActionRegistry();
    $registry->registerAll($holders);

    return [new ActionRunner($registry, $orm, $gate ?? new AllowAllGate(), new Validator($orm)), $fixture];
}

describe('registration', function () {
    it('registers only the methods marked #[Action]', function () {
        $compiled = (new ActionRegistry())->register(HelperMethodActions::class);

        expect(array_map(static fn ($a): string => $a->name, $compiled))->toBe(['HelperMethodActions.run']);
    });

    it('registers several holders at once, in order', function () {
        $registry = new ActionRegistry();

        $compiled = $registry->registerAll([HelperMethodActions::class, ScalarActions::class]);

        expect(array_map(static fn ($a): string => $a->name, $compiled))
            ->toBe(['HelperMethodActions.run', 'ScalarActions.repeat'])
            ->and($registry->has('ScalarActions.repeat'))->toBeTrue();
    });

    it('refuses a static action', function () {
        expect(fn () => (new ActionRegistry())->register(StaticActions::class))
            ->toThrow(ActionException::class, 'is static; an action is an instance method');
    });

    it('refuses a parameter type it cannot bind', function (string $holder, string $type) {
        expect(fn () => (new ActionRegistry())->register($holder))
            ->toThrow(ActionException::class, "\$when as {$type}, which an action cannot bind");
    })->with([
        'a union' => [UnionParameterActions::class, 'string|int'],
        'a builtin that is not a scalar or array' => [ObjectParameterActions::class, 'object'],
    ]);

    it('records a nullable or defaulted model parameter as optional', function () {
        $compiled = (new ActionRegistry())->register(OptionalSiteActions::class);

        expect($compiled[0]->optionalModels)->toBe(['site' => true])
            ->and($compiled[1]->optionalModels)->toBe(['site' => true]);
    });

    it('puts class middleware first and does not repeat a method middleware', function () {
        $compiled = (new ActionRegistry())->register(MiddlewareActions::class);

        expect($compiled[0]->middleware)->toBe(['mw.web', 'mw.auth', 'mw.audit']);
    });

    it('builds handlers through the resolver it was given, once per call', function () {
        $built = 0;
        $registry = new ActionRegistry(static function (string $class) use (&$built): object {
            $built++;

            return new $class('hello');
        });
        $registry->register(InjectedActions::class);
        $action = $registry->get('InjectedActions.greet');

        $first = $registry->instanceFor($action);
        $second = $registry->instanceFor($action);

        expect($first)->not->toBe($second)
            ->and($built)->toBe(2)
            ->and($first->greet()->value)->toBe('hello');
    });
});

describe('binding', function () {
    it('binds an absent optional model as its default, or null', function (string $action) {
        [$runner] = surfaceRunner([OptionalSiteActions::class]);

        expect($runner->run($action, [])->value)->toBe('none');
    })->with(['OptionalSiteActions.defaulted', 'OptionalSiteActions.nullable']);

    // Nothing to decide about a model that is not there -- but one that is
    // supplied is decided about. The absent case used to pass a deny gate
    // with nothing asked; the class check beside it now decides it.
    it('skips the check on an absent optional model, and runs it on a supplied one', function () {
        $gate = new AllowAllGate();
        [$runner, $fixture] = surfaceRunner([OptionalSiteActions::class], $gate);

        $runner->run('OptionalSiteActions.defaulted', ['site' => '']);
        $absent = array_column($gate->checks, 'ability');
        $runner->run('OptionalSiteActions.defaulted', ['site' => $fixture->ids['sites'][0]]);

        expect($absent)->toBe(['viewAny'])
            ->and(array_column($gate->checks, 'ability'))->toBe(['viewAny', 'viewAny', 'view']);
    });

    it('lets a deny gate stop it either way', function () {
        [$runner, $fixture] = surfaceRunner([OptionalSiteActions::class], new DenyGate());

        expect(fn () => $runner->run('OptionalSiteActions.defaulted', ['site' => '']))
            ->toThrow(ForbiddenException::class)
            ->and(fn () => $runner->run('OptionalSiteActions.defaulted', ['site' => $fixture->ids['sites'][0]]))
            ->toThrow(ForbiddenException::class);
    });

    it('resolves a supplied optional model', function () {
        [$runner, $fixture] = surfaceRunner([OptionalSiteActions::class]);

        expect($runner->run('OptionalSiteActions.nullable', ['site' => $fixture->ids['sites'][0]])->value)
            ->toBe('LAX0');
    });

    it('404s on an identifier that is neither a string nor an integer', function (mixed $id) {
        [$runner] = surfaceRunner([OptionalSiteActions::class]);

        expect(fn () => $runner->run('OptionalSiteActions.defaulted', ['site' => $id]))
            ->toThrow(NotFoundException::class, "needs an identifier for 'site'");
    })->with([
        'a list' => [['x']],
        'a float' => [1.5],
        'a bool' => [true],
    ]);

    it('hands the body the Request it was called with', function () {
        [$runner] = surfaceRunner([RequestActions::class]);

        $result = $runner->run('RequestActions.path', [], request: Request::post('/_action/RequestActions.path'));

        expect($result->value)->toBe('/_action/RequestActions.path');
    });

    it('refuses to run an action that asks for a Request when none was supplied', function () {
        [$runner] = surfaceRunner([RequestActions::class]);

        expect(fn () => $runner->run('RequestActions.path', []))
            ->toThrow(\LogicException::class, 'asks for the Request, but none was supplied');
    });

    it('coerces a supplied scalar and falls back to the default for an absent one', function () {
        [$runner] = surfaceRunner([ScalarActions::class]);

        expect($runner->run('ScalarActions.repeat', ['word' => 'ab'])->value)->toBe('ab')
            ->and($runner->run('ScalarActions.repeat', ['word' => 'ab', 'times' => '3'])->value)->toBe('ababab');
    });

    it('binds a form request that declares no fields', function () {
        [$runner] = surfaceRunner([NoFieldsActions::class]);

        expect($runner->run('NoFieldsActions.submit', ['ignored' => 'x'])->value)->toBe(NoFieldsRequest::class);
    });
});

describe('ActionResult::withValue', function () {
    it('replaces the value and keeps everything else', function () {
        $result = ActionResult::revalidate('racks.show')
            ->withTags('rack:1')
            ->flash(['message' => 'saved'])
            ->withValue(['id' => 1]);

        expect($result->value)->toBe(['id' => 1])
            ->and($result->revalidatedRoutes)->toBe(['racks.show'])
            ->and($result->revalidatedTags)->toBe(['rack:1'])
            ->and($result->flash)->toBe(['message' => 'saved'])
            ->and($result->status)->toBe(200);
    });

    // A value on a redirect used to be kept here and then dropped by the
    // endpoint, which answers a redirect with no body. It is now refused
    // when the result is built (see RedirectCarriesNothingTest); a null value
    // is no value, and still keeps the redirect and its status.
    it('keeps a redirect and its status', function () {
        $result = ActionResult::redirect('/racks')->withTags('rack:1')->withValue(null);

        expect($result->isRedirect())->toBeTrue()
            ->and($result->redirect)->toBe('/racks')
            ->and($result->status)->toBe(303)
            ->and($result->revalidatedTags)->toBe(['rack:1'])
            ->and($result->value)->toBeNull();
    });
});
