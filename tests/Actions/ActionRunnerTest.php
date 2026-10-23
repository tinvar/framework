<?php

declare(strict_types=1);

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\DenyGate;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\NotFoundException;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/actions.php';

/** @return array{0: ActionRunner, 1: Fixture, 2: Orm} */
function actionRunner(?object $gate = null): array
{
    A\Trace::reset();
    $fixture = new Fixture(racks: 2, unitsPerRack: 2, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $registry = new ActionRegistry();
    $registry->register(A\RackActions::class);

    $runner = new ActionRunner($registry, $orm, $gate ?? new AllowAllGate(), new Validator($orm));

    return [$runner, $fixture, $orm];
}

describe('registration', function () {
    it('names an action after its class and method', function () {
        expect((new ActionRegistry())->register(A\RackActions::class)[0]->name)->toBe('RackActions.moveUnit');
    });

    // The build rule, enforced where it cannot be skipped.
    it('refuses a model input that no check names', function () {
        expect(fn () => (new ActionRegistry())->register(A\UnguardedActions::class))
            ->toThrow(ActionException::class, 'no #[Authorize] naming it');
    });

    it('accepts a declared, reasoned opt-out', function () {
        expect((new ActionRegistry())->register(A\RackActions::class))
            ->toHaveCount(4);
    });

    it('refuses a parameter type it cannot bind', function () {
        expect(fn () => (new ActionRegistry())->register(A\UnbindableActions::class))
            ->toThrow(ActionException::class, 'cannot bind');
    });

    it('refuses two actions sharing a name', function () {
        $registry = new ActionRegistry();
        $registry->register(A\RackActions::class);

        expect(fn () => $registry->register(A\RackActions::class))
            ->toThrow(ActionException::class, 'Two actions are named');
    });
});

describe('the call order', function () {
    it('runs the body and returns what it produced', function () {
        [$runner, $fixture] = actionRunner();
        $rack = $fixture->ids['racks'][0];

        $result = $runner->run('RackActions.moveUnit', ['rack' => $rack, 'position' => '12']);

        expect($result)->toBeInstanceOf(ActionResult::class)
            ->and($result->revalidatedTags)->toBe(["rack:{$rack}"])
            ->and($result->revalidatedRoutes)->toBe(['racks.show'])
            ->and($result->flash)->toBe(['message' => 'Moved to 12'])
            ->and(A\Trace::$events)->toBe(['body:moveUnit']);
    });

    it('404s on an id that does not resolve, before authorizing', function () {
        [$runner] = actionRunner(new DenyGate());

        // A 404 rather than a 403: the row is not in scope, and the response
        // must not say whether it exists elsewhere.
        expect(fn () => $runner->run('RackActions.moveUnit', [
            'rack' => '0192f3a1-0000-7000-8000-000000000000',
            'position' => '12',
        ]))->toThrow(NotFoundException::class);
    });

    it('denies before validating or running the body', function () {
        [$runner, $fixture] = actionRunner(new DenyGate());

        // position is invalid too; authorization must refuse first.
        expect(fn () => $runner->run('RackActions.moveUnit', [
            'rack' => $fixture->ids['racks'][0],
            'position' => '999',
        ]))->toThrow(ForbiddenException::class);

        expect(A\Trace::$events)->toBe([]);
    });

    it('validates after authorizing and before the body', function () {
        [$runner, $fixture] = actionRunner();

        expect(fn () => $runner->run('RackActions.moveUnit', [
            'rack' => $fixture->ids['racks'][0],
            'position' => '999',
        ]))->toThrow(ValidationException::class);

        expect(A\Trace::$events)->toBe([]);
    });

    it('runs every declared check, not just the first', function () {
        $gate = new AllowAllGate();
        [$runner, $fixture] = actionRunner($gate);

        $runner->run('RackActions.transfer', [
            'rack' => $fixture->ids['racks'][0],
            'site' => $fixture->ids['sites'][0],
        ]);

        expect($gate->checks)->toBe([
            ['ability' => 'update', 'argument' => 'rack'],
            ['ability' => 'view', 'argument' => 'site'],
        ]);
    });

    it('hands the gate resolved models, so a real policy can decide', function () {
        $auth = new AuthContext();
        $policies = (new PolicyRegistry())->register(
            Tinvar\Tests\Orm\Fixtures\Rack::class,
            new class () {
                public function update(?object $user, object $rack): bool
                {
                    return $rack->name === 'R-02';
                }
            }::class,
        );

        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new ActionRegistry();
        $registry->register(A\RackActions::class);
        $runner = new ActionRunner($registry, $orm, new PolicyGate($policies, $auth), new Validator($orm));

        expect(fn () => $runner->run('RackActions.moveUnit', [
            'rack' => $fixture->ids['racks'][0], 'position' => '1',
        ]))->toThrow(ForbiddenException::class);

        expect($runner->run('RackActions.moveUnit', [
            'rack' => $fixture->ids['racks'][1], 'position' => '1',
        ]))->toBeInstanceOf(ActionResult::class);
    });
});

describe('Precognition', function () {
    it('authorizes and validates but does not run the body', function () {
        [$runner, $fixture] = actionRunner();

        $result = $runner->run('RackActions.moveUnit', [
            'rack' => $fixture->ids['racks'][0],
            'position' => '12',
        ], precognitive: true);

        expect($result)->toBeInstanceOf(ActionResult::class)
            ->and(A\Trace::$events)->toBe([], 'the body did not run');
    });

    it('still reports validation errors', function () {
        [$runner, $fixture] = actionRunner();

        expect(fn () => $runner->run('RackActions.moveUnit', [
            'rack' => $fixture->ids['racks'][0],
            'position' => '0',
        ], precognitive: true))->toThrow(ValidationException::class);
    });

    it('still denies', function () {
        [$runner, $fixture] = actionRunner(new DenyGate());

        expect(fn () => $runner->run('RackActions.moveUnit', [
            'rack' => $fixture->ids['racks'][0],
            'position' => '12',
        ], precognitive: true))->toThrow(ForbiddenException::class);
    });

    it('validates one field at a time', function () {
        [$runner, $fixture] = actionRunner();

        expect($runner->validateField('RackActions.moveUnit', [
            'rack' => $fixture->ids['racks'][0], 'position' => '99',
        ], 'position'))->toHaveCount(1);

        expect($runner->validateField('RackActions.moveUnit', [
            'rack' => $fixture->ids['racks'][0], 'position' => '12',
        ], 'position'))->toBe([]);
    });
});

it('refuses a body that does not return an ActionResult', function () {
    [$runner, $fixture] = actionRunner();

    expect(fn () => $runner->run('RackActions.wrongReturn', ['rack' => $fixture->ids['racks'][0]]))
        ->toThrow(ActionException::class, 'returns an ActionResult');
});

it('runs an action with no model inputs at all', function () {
    [$runner] = actionRunner();

    expect($runner->run('RackActions.ping', [])->value)->toBe('pong');
});

describe('ActionResult', function () {
    it('carries revalidation, flash and a redirect', function () {
        $result = ActionResult::revalidate('racks.show')->withTags('rack:1')->flash(['message' => 'ok']);

        expect($result->revalidatedRoutes)->toBe(['racks.show'])
            ->and($result->revalidatedTags)->toBe(['rack:1'])
            ->and($result->flash)->toBe(['message' => 'ok'])
            ->and($result->isRedirect())->toBeFalse();
    });

    it('is a 303 when it redirects, as the protocol requires after a write', function () {
        expect(ActionResult::redirect('/racks')->status)->toBe(303);
    });

    // Dropping one loses the revalidation and the flash silently.
    it('marks every factory and builder NoDiscard', function (string $method) {
        expect((new ReflectionMethod(ActionResult::class, $method))->getAttributes('NoDiscard'))
            ->not->toBeEmpty();
    })->with(['ok', 'revalidate', 'revalidateTags', 'redirect', 'withRoutes', 'withTags', 'flash', 'withValue']);
});
