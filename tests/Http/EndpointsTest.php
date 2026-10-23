<?php

declare(strict_types=1);

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\Endpoints\QueryEndpoint;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\RequireAuthentication;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Actions/Fixtures/actions.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';

/** @return array{0: ActionEndpoint, 1: Fixture} */
function actionEndpoint(): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(A\RackActions::class);

    $runner = new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm));

    return [new ActionEndpoint($runner), $fixture];
}

describe('the action endpoint', function () {
    it('runs an action and returns what it produced', function () {
        [$endpoint, $fixture] = actionEndpoint();

        $response = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit'),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        $body = json_decode($response->body(), true);

        expect($response->status)->toBe(200)
            ->and($body['flash'])->toBe(['message' => 'Moved to 12'])
            ->and($body['revalidated'])->toBe(['racks.show'])
            ->and($response->header('cache-control'))->toBe('private, no-store');
    });

    // "an action's response lists the server-side query tags it invalidated"
    it('reports the tags it invalidated', function () {
        [$endpoint, $fixture] = actionEndpoint();
        $rack = $fixture->ids['racks'][0];

        $response = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit'),
            json_encode(['rack' => $rack, 'position' => 12]),
        );

        expect($response->header('x-invalidated-tags'))->toBe("rack:{$rack}");
    });

    // "Actions are always POST, so no _method spoofing."
    it('refuses anything but POST', function (string $method) {
        [$endpoint, $fixture] = actionEndpoint();
        $request = new Request($method, '/_action/RackActions.ping');

        expect(fn () => $endpoint->handle($request))->toThrow(HttpException::class, 'POST only');
    })->with(['GET', 'PUT', 'PATCH', 'DELETE']);

    it('returns validation errors with the payload, not a redirect', function () {
        [$endpoint, $fixture] = actionEndpoint();

        $response = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit'),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 999]),
        );

        expect($response->status)->toBe(422)
            ->and(json_decode($response->body(), true)['errors'])->toHaveKey('position');
    });

    it('validates without running the body when Precognition asks', function () {
        [$endpoint, $fixture] = actionEndpoint();
        A\Trace::reset();

        $response = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit', headers: ['Precognition' => 'true']),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        expect($response->status)->toBe(200)
            ->and(A\Trace::$events)->toBe([]);
    });

    it('validates one named field when asked for only that', function () {
        [$endpoint, $fixture] = actionEndpoint();

        $bad = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit', headers: [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => 'position',
            ]),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 999]),
        );

        $good = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit', headers: [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => 'position',
            ]),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        expect($bad->status)->toBe(422)
            ->and($good->status)->toBe(204);
    });
});

describe('endpoint limits', function () {
    it('refuses a body over the cap', function () {
        expect(fn () => Limits::decodeJson(json_encode(['x' => str_repeat('a', 2_000_000)])))
            ->toThrow(HttpException::class, 'too large');
    });

    it('refuses JSON nested past the depth limit', function () {
        $deep = '1';
        for ($i = 0; $i < Limits::MAX_JSON_DEPTH + 5; $i++) {
            $deep = "[{$deep}]";
        }

        expect(fn () => Limits::decodeJson('{"a":' . $deep . '}'))
            ->toThrow(HttpException::class, 'depth limit');
    });

    it('refuses a body that is not an object', function (string $body) {
        expect(fn () => Limits::decodeJson($body))->toThrow(HttpException::class);
    })->with(['[1,2,3]', '"a string"', 'not json at all']);

    it('accepts an empty body as no input', function () {
        expect(Limits::decodeJson(''))->toBe([]);
    });

    it('does not put the body in the error', function () {
        try {
            Limits::decodeJson('{"secret": "hunter2", ');
            expect()->fail('malformed JSON was accepted');
        } catch (HttpException $e) {
            expect($e->getMessage())->not->toContain('hunter2');
        }
    });
});

describe('CSRF', function () {
    $next = static fn (Request $r): Response => Response::make(200, 'ok');

    it('lets a matching cookie and header through', function () use ($next) {
        $request = new Request('POST', '/_action/x', ['x-xsrf-token' => 'abc'], cookies: [
            VerifyCsrfToken::COOKIE => 'abc',
        ]);

        expect((new VerifyCsrfToken())->handle($request, $next)->status)->toBe(200);
    });

    it('refuses a mismatch with 419, not an error screen', function () use ($next) {
        $request = new Request('POST', '/_action/x', ['x-xsrf-token' => 'wrong'], cookies: [
            VerifyCsrfToken::COOKIE => 'abc',
        ]);

        try {
            (new VerifyCsrfToken())->handle($request, $next);
            expect()->fail('a mismatched token was accepted');
        } catch (HttpException $e) {
            expect($e->status)->toBe(419);
        }
    });

    it('refuses a missing token', function () use ($next) {
        expect(fn () => (new VerifyCsrfToken())->handle(new Request('POST', '/_action/x'), $next))
            ->toThrow(HttpException::class);
    });

    // <Form> renders a hidden field so a form works before JavaScript loads.
    it('accepts the hidden form field', function () use ($next) {
        $request = new Request('POST', '/_action/x', [], [], ['_token' => 'abc'], [
            VerifyCsrfToken::COOKIE => 'abc',
        ]);

        expect((new VerifyCsrfToken())->handle($request, $next)->status)->toBe(200);
    });

    it('leaves a read-only method alone', function (string $method) use ($next) {
        expect((new VerifyCsrfToken())->handle(new Request($method, '/racks'), $next)->status)->toBe(200);
    })->with(['GET', 'HEAD', 'OPTIONS']);
});

describe('authentication', function () {
    it('refuses an anonymous caller with 401 and a clear code', function () {
        $middleware = new RequireAuthentication(new AuthContext());

        try {
            $middleware->handle(Request::post('/_action/x'), static fn (): Response => Response::make());
            expect()->fail('an anonymous caller was accepted');
        } catch (HttpException $e) {
            expect($e->status)->toBe(401)->and($e->code_)->toBe('unauthenticated');
        }
    });

    it('runs in the pipeline before anything downstream', function () {
        $reached = false;
        $pipeline = new Pipeline([new RequireAuthentication(new AuthContext())]);

        expect(fn () => $pipeline->run(Request::post('/_action/x'), function () use (&$reached): Response {
            $reached = true;

            return Response::make();
        }))->toThrow(HttpException::class);

        expect($reached)->toBeFalse();
    });
});

describe('the query endpoint', function () {
    /** @return array{0: QueryEndpoint, 1: Fixture} */
    $endpoint = function (array $allowed): array {
        $fixture = new Fixture(racks: 2, unitsPerRack: 2);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new QueryRegistry(static fn (string $c): object => new $c());
        $registry->register(Tinvar\Tests\Query\Fixtures\RackSummary::class);
        $runner = new QueryRunner($registry, $orm, new AllowAllGate());

        return [new QueryEndpoint($runner, static fn (): array => $allowed), $fixture];
    };

    it('answers an allowed query', function () use ($endpoint) {
        [$query, $fixture] = $endpoint(['racks.summary']);

        $response = $query->handle(
            Request::post('/_query/racks.summary'),
            json_encode(['args' => ['rack' => $fixture->ids['racks'][0]]]),
        );

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true)['value']['units'])->toBe(2)
            ->and($response->header('vary'))->toBe('Cookie');
    });

    // The allowlist applies over HTTP exactly as it does over the socket.
    it('refuses a query the route does not allow', function () use ($endpoint) {
        [$query, $fixture] = $endpoint([]);

        $response = $query->handle(
            Request::post('/_query/racks.summary'),
            json_encode(['args' => ['rack' => $fixture->ids['racks'][0]]]),
        );

        expect($response->status)->toBe(422)
            ->and(json_decode($response->body(), true)['error']['code'])->toBe('query_not_allowed');
    });

    it('refuses anything but POST', function () use ($endpoint) {
        [$query] = $endpoint(['racks.summary']);

        expect(fn () => $query->handle(new Request('GET', '/_query/racks.summary')))
            ->toThrow(HttpException::class, 'POST only');
    });
});
