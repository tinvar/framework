<?php

declare(strict_types=1);

/** Regressions for the actions review. Each was reproduced before being fixed. */

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Tests\Auth\Fixtures\User as AuthUser;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Data\Encoder;
use Tinvar\Http\NotFoundException;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';
require_once __DIR__ . '/Fixtures/actions.php';

/** @return array{0: ActionRunner, 1: Fixture, 2: Orm} */
function runnerOf(string $holder): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register($holder);

    return [new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm)), $fixture, $orm];
}

/**
 * A runner with a REAL gate. runnerOf() uses AllowAllGate, which ignores the
 * subject entirely -- so it cannot see a check that was handed a subject that
 * is not there. That is exactly how the optional-model fail hid.
 *
 * @return array{0: ActionRunner, 1: Fixture, 2: Orm}
 */
function policyRunnerOf(string $holder): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register($holder);

    $auth = new AuthContext();
    $auth->login(new AuthUser('u-1'));

    $policies = (new PolicyRegistry())->registerAll([
        Rack::class => A\RecordingRackPolicy::class,
        Site::class => A\RecordingSitePolicy::class,
    ]);

    A\RecordingRackPolicy::reset();

    return [
        new ActionRunner($registry, $orm, new PolicyGate($policies, $auth), new Validator($orm)),
        $fixture,
        $orm,
    ];
}

// The collision check only saw parameters already processed, so the same
// signature was refused or accepted depending on declaration order.
it('refuses a name meaning two models, whichever order they are declared', function (string $holder) {
    expect(fn () => (new ActionRegistry())->register($holder))
        ->toThrow(ActionException::class, 'resolves');
})->with([
    'form request first' => [A\CollisionFirstActions::class],
    'model parameter first' => [A\CollisionLastActions::class],
]);

it('refuses a variadic model parameter instead of binding exactly one', function () {
    expect(fn () => (new ActionRegistry())->register(A\VariadicActions::class))
        ->toThrow(ActionException::class, 'variadic');
});

describe('optional model inputs', function () {
    it('allows a nullable model field to be absent', function () {
        [$runner, $fixture] = runnerOf(A\OptionalActions::class);

        expect($runner->run('OptionalActions.move', ['rack' => $fixture->ids['racks'][0]])->value)
            ->toBe('nowhere');
    });

    // ActionRegistry REQUIRES a check naming every model input, and records the
    // same input as optional. Under a real gate those two rules were mutually
    // unsatisfiable: the check was evaluated with no subject and threw an
    // AuthorizationException -- which is not an HttpException, so a 500.
    it('skips a check whose optional subject the caller did not supply', function () {
        [$runner, $fixture] = policyRunnerOf(A\OptionalActions::class);

        $result = $runner->run('OptionalActions.move', ['rack' => $fixture->ids['racks'][0]]);

        expect($result->value)->toBe('nowhere')
            ->and(A\RecordingRackPolicy::$asked)->toBe(['rack.update'], 'the absent subject was never asked about');
    });

    it('still runs that check when the optional subject is supplied', function () {
        [$runner, $fixture] = policyRunnerOf(A\OptionalActions::class);

        $result = $runner->run('OptionalActions.move', [
            'rack' => $fixture->ids['racks'][0],
            'destination' => $fixture->ids['sites'][0],
        ]);

        expect($result->value)->toBe('LAX0')
            ->and(A\RecordingRackPolicy::$asked)->toBe(['rack.update', 'site.view']);
    });

    // The narrow part of the rule: absence only excuses a check when the entry
    // point itself declared that input optional. A required subject that is
    // missing must never let a caller past the check by omitting it.
    it('does not let a caller skip a check by omitting a required subject', function () {
        [$runner] = policyRunnerOf(A\OptionalActions::class);

        // 404, not "allowed because the subject was absent": a required model
        // that does not resolve never reaches the gate at all.
        expect(fn () => $runner->run('OptionalActions.move', []))
            ->toThrow(NotFoundException::class)
            ->and(A\RecordingRackPolicy::$asked)->toBe([]);
    });

    it('still resolves it when supplied', function () {
        [$runner, $fixture] = runnerOf(A\OptionalActions::class);

        expect($runner->run('OptionalActions.move', [
            'rack' => $fixture->ids['racks'][0],
            'destination' => $fixture->ids['sites'][0],
        ])->value)->toBe('LAX0');
    });

    it('still 404s on a supplied id that does not resolve', function () {
        [$runner, $fixture] = runnerOf(A\OptionalActions::class);

        expect(fn () => $runner->run('OptionalActions.move', [
            'rack' => $fixture->ids['racks'][0],
            'destination' => '0192f3a1-0000-7000-8000-000000000000',
        ]))->toThrow(NotFoundException::class);
    });
});

describe('scalar parameters are coerced, not passed through', function () {
    it('reports a 422 rather than a TypeError for the wrong shape', function () {
        [$runner, $fixture] = runnerOf(A\OptionalActions::class);

        expect(fn () => $runner->run('OptionalActions.rename', [
            'rack' => $fixture->ids['racks'][0],
            'name' => ['array', 'not', 'string'],
        ]))->toThrow(ValidationException::class);
    });

    it('reports a missing scalar as a field error, not a 404', function () {
        [$runner, $fixture] = runnerOf(A\OptionalActions::class);

        expect(fn () => $runner->run('OptionalActions.rename', ['rack' => $fixture->ids['racks'][0]]))
            ->toThrow(ValidationException::class);
    });
});

describe('Precognition', function () {
    // A half-filled form is the whole point; binding the call first 404'd on
    // the missing scalar before validation could report anything.
    it('does not fail on a missing scalar parameter', function () {
        [$runner, $fixture] = runnerOf(A\OptionalActions::class);

        expect($runner->run('OptionalActions.rename', ['rack' => $fixture->ids['racks'][0]], precognitive: true))
            ->not->toBeNull();
    });

    it('consults every form request, not just the first', function () {
        [$runner] = runnerOf(A\TwoRequestActions::class);

        expect($runner->validateField('TwoRequestActions.both', ['beta' => 'xy'], 'beta'))
            ->toHaveCount(1)
            ->and($runner->validateField('TwoRequestActions.both', ['alpha' => 'xy'], 'alpha'))
            ->toHaveCount(1);
    });

    it('refuses a field name no form request declares', function () {
        [$runner] = runnerOf(A\TwoRequestActions::class);

        expect(fn () => $runner->validateField('TwoRequestActions.both', [], 'gamma'))
            ->toThrow(LogicException::class, 'no form-request field named');
    });
});

// With two model inputs, exempting "the row being edited" has to pick the one
// of the rule's own class.
it('exempts the row of the rule\'s own class, not whichever model came first', function () {
    [$runner, $fixture, $orm] = runnerOf(A\TwoModelActions::class);
    $site = $orm->find(Site::class, $fixture->ids['sites'][0]);

    // The site keeps its own code: its own row is not a collision.
    $result = $runner->run('TwoModelActions.renameSite', [
        'rack' => $fixture->ids['racks'][0],
        'site' => $fixture->ids['sites'][0],
        'code' => $site->code,
    ]);

    expect($result->value)->toBe($site->code);
});

/**
 * A class-level declaration must not blanket the actions beside it. The
 * registry is the check that cannot be skipped, so it has to be at least as
 * strict as the build rules.
 */
describe('class-level declarations do not cover action methods', function () {
    // Refused loudly rather than silently ignored: the author wrote the
    // class-level attribute expecting it to cover these.
    it('refuses an action a class-level opt-out would have exempted', function () {
        expect(fn () => (new ActionRegistry())->register(A\BlanketExemptActions::class))
            ->toThrow(ActionException::class, 'does not cover action methods');
    });

    it('refuses an action a read-only class check would have satisfied', function () {
        expect(fn () => (new ActionRegistry())->register(A\BlanketCheckActions::class))
            ->toThrow(ActionException::class, 'does not cover action methods');
    });

    it('refuses a check that names no subject', function () {
        expect(fn () => (new ActionRegistry())->register(A\SubjectlessActions::class))
            ->toThrow(ActionException::class, 'no subject');
    });
});

it('builds a fresh handler per call, so nothing memoised leaks between them', function () {
    [$runner, $fixture] = runnerOf(A\StatefulActions::class);

    $runner->run('StatefulActions.touch', ['rack' => $fixture->ids['racks'][0]]);
    $second = $runner->run('StatefulActions.touch', ['rack' => $fixture->ids['racks'][1]]);

    expect($second->value)->toBeNull('the second call did not see the first');
});

it('does not put the requested action name in the error', function () {
    [$runner] = runnerOf(A\OptionalActions::class);

    try {
        $runner->run("evil\r\nFAKE-LOG-LINE", []);
        expect()->fail('an unknown action was accepted');
    } catch (NotFoundException $e) {
        expect($e->getMessage())->not->toContain('FAKE-LOG-LINE');
    }
});

// revalidateTags() set an x-invalidated-tags header for the CLIENT's prefetch
// cache and nothing else: QueryCache::forgetTags() had no caller in src/ at
// all, so a cached query kept serving the pre-write answer for its full TTL.
describe('tag invalidation reaches the server cache', function () {
    it('forgets the tags an action revalidated', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $store = new ArrayStore();
        $cache = new QueryCache($store, new AuthContext());

        $rack = $fixture->ids['racks'][0];
        $declaration = new QueryAttribute(
            cache: '5m',
            tags: static function (string $rack): array {
                return ['rack:' . $rack];
            },
        );
        $tenant = $orm->tenant();
        $cache->put('racks.cached', $declaration, ['rack' => $rack], $tenant, ['n' => 1]);

        expect($cache->get('racks.cached', $declaration, ['rack' => $rack], $tenant)['hit'])
            ->toBeTrue('cached before the write');

        $registry = new ActionRegistry();
        $registry->register(A\RackActions::class);
        $runner = new ActionRunner(
            $registry,
            $orm,
            new AllowAllGate(),
            new Validator($orm),
            new Encoder(),
            $cache,
        );

        $runner->run('RackActions.moveUnit', ['rack' => $rack, 'position' => 12]);

        expect($cache->get('racks.cached', $declaration, ['rack' => $rack], $tenant)['hit'])
            ->toBeFalse('the action invalidated it');
    });

    // put() namespaces tags with the live tenant, so invalidating under the
    // wrong one silently does nothing. forgetTags() used to default to ''.
    it('invalidates within the acting tenant, not a default one', function () {
        $store = new ArrayStore();
        $cache = new QueryCache($store, new AuthContext());

        $declaration = new QueryAttribute(
            cache: '5m',
            tags: static function (string $rack): array {
                return ['rack:' . $rack];
            },
        );
        $cache->put('q', $declaration, ['rack' => 'r1'], 'acme', ['n' => 1]);

        expect($cache->forgetTags(['rack:r1'], 'globex'))->toBe(0, 'another tenant cannot drop it')
            ->and($cache->forgetTags(['rack:r1'], 'acme'))->toBe(1);
    });
});
