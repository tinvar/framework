<?php

declare(strict_types=1);

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\Endpoints\QueryEndpoint;
use Tinvar\Http\ExceptionHandler;
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
use Tinvar\Routing\RoutingException;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
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

/**
 * The decoder used to be chosen by whether the raw body was empty. A form
 * posted before JavaScript loads is urlencoded and has a raw body, so it went
 * to the JSON decoder and came back 400; only multipart, whose raw body PHP
 * consumes, got through.
 */
describe('reading a body by its Content-Type', function () {
    it('runs an action posted as a form, whatever its encoding', function (string $type) {
        [$endpoint, $fixture] = actionEndpoint();
        $fields = ['rack' => $fixture->ids['racks'][0], 'position' => '12', '_token' => 'from-the-hidden-field'];
        $request = new Request('POST', '/_action/RackActions.moveUnit', ['content-type' => $type], [], $fields);

        // PHP leaves php://input empty for multipart, and full for urlencoded.
        $raw = str_starts_with($type, 'multipart/') ? '' : http_build_query($fields);

        expect($endpoint->handle($request, $raw)->status)->toBe(200);
    })->with([
        'urlencoded' => 'application/x-www-form-urlencoded',
        'multipart' => 'multipart/form-data; boundary=----x',
    ]);

    it('decodes JSON labelled with a charset', function () {
        [$endpoint, $fixture] = actionEndpoint();
        $request = new Request('POST', '/_action/RackActions.moveUnit', ['content-type' => 'application/json; charset=utf-8']);

        expect($endpoint->handle($request, json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]))->status)
            ->toBe(200);
    });

    it('refuses a body of any other type with 415 rather than guess', function (string $type) {
        $request = new Request('POST', '/_action/x', ['content-type' => $type]);

        try {
            Limits::input($request, '{"rack": "r"}');
            expect()->fail("a {$type} body was read");
        } catch (HttpException $e) {
            expect($e->status)->toBe(415);
        }
    })->with(['text/plain', 'application/xml', 'application/octet-stream']);

    it('reads an untyped body as the endpoints\' own JSON, or else what was parsed for it', function () {
        expect(Limits::input(Request::post('/_action/x'), '{"a": 1}'))->toBe(['a' => 1])
            ->and(Limits::input(Request::post('/_action/x', ['b' => '2']), ''))->toBe(['b' => '2'])
            ->and(Limits::input(Request::post('/_action/x'), ''))->toBe([]);
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

        // 404, not 403 and not a blanket 422: the name is not callable here,
        // and saying so must not confirm that it exists somewhere else.
        expect($response->status)->toBe(404)
            ->and(json_decode($response->body(), true)['error']['code'])->toBe('query_not_allowed');
    });

    it('refuses anything but POST', function () use ($endpoint) {
        [$query] = $endpoint(['racks.summary']);

        expect(fn () => $query->handle(new Request('GET', '/_query/racks.summary')))
            ->toThrow(HttpException::class, 'POST only');
    });
});

// Regressions from the phase-1 security review. Each was reproduced first.
describe('endpoint hardening', function () {
    // The encoder guards queries and a return-type check guards loaders, but
    // an ActionResult went to the browser through json_encode() with nothing
    // in the way -- and json_encode forced every lazy relation on the way out.
    it('refuses to send a model back from an action', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new ActionRegistry();
        $registry->register(A\LeakyActions::class);
        $runner = new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm));

        expect(fn () => $runner->run('LeakyActions.leak', ['rack' => $fixture->ids['racks'][0]]))
            ->toThrow(DataBoundaryException::class);
    });

    it('refuses a model smuggled through flash', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new ActionRegistry();
        $registry->register(A\LeakyActions::class);
        $runner = new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm));

        expect(fn () => $runner->run('LeakyActions.leakViaFlash', ['rack' => $fixture->ids['racks'][0]]))
            ->toThrow(DataBoundaryException::class);
    });

    // PHP decodes {} to [], and array_is_list([]) is true, so the list guard
    // made every argument-less action and query unreachable over HTTP.
    it('accepts an empty JSON object as a body', function () {
        [$endpoint] = actionEndpoint();

        $response = $endpoint->handle(Request::post('/_action/RackActions.ping'), '{}');

        expect($response->status)->toBe(200);
    });

    it('still refuses a JSON array as a body', function () {
        expect(fn () => Limits::decodeJson('[1,2,3]'))
            ->toThrow(HttpException::class, 'must be a JSON object');
    });

    // The field name is client-supplied; validateField() throws an
    // UnknownField quoting it, and only ValidationException was caught.
    it('answers 422 for a Precognition field the action does not have', function () {
        [$endpoint, $fixture] = actionEndpoint();

        $request = Request::post('/_action/RackActions.moveUnit', headers: [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => '<script>alert(1)</script>',
        ]);

        try {
            $endpoint->handle($request, json_encode(['rack' => $fixture->ids['racks'][0]]));
            $response = null;
        } catch (Throwable $e) {
            $response = (new ExceptionHandler())->handle($e, $request);
        }

        expect($response?->status)->toBe(422)
            ->and($response?->body())->not->toContain('<script>', 'no client text echoed back')
            ->and($response?->body())->not->toContain('alert');
    });

    // Only the unknown field is the client's doing. A rule the author
    // misconfigured is a bug, and answering it as "no such field" hid it.
    it('lets a misconfigured rule out of Precognition as the bug it is, not as an unknown field', function () {
        $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new ActionRegistry();
        $registry->register(PrecognitionMisconfiguredActions::class);
        $endpoint = new ActionEndpoint(new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm)));

        $request = Request::post('/_action/PrecognitionMisconfiguredActions.save', headers: [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'code',
        ]);

        expect(fn () => $endpoint->handle($request, json_encode(['code' => 'X'])))
            ->toThrow(LogicException::class, 'set to ignore the subject');
    });

    it('bounds how many fields one Precognition header may name', function () {
        [$endpoint, $fixture] = actionEndpoint();

        $many = implode(',', array_fill(0, Limits::MAX_PRECOGNITION_FIELDS + 1, 'position'));

        expect(fn () => $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit', headers: [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => $many,
            ]),
            json_encode(['rack' => $fixture->ids['racks'][0]]),
        ))->toThrow(HttpException::class, 'Too many fields');
    });
});

// The endpoints raise; something has to classify what they raise. Nothing did:
// Router::handle() had no catch, the endpoints caught only ValidationException,
// and the carefully-written messages name classes, methods and ids.
describe('the exception handler', function () {
    it('maps an HttpException to its status without forwarding the message', function () {
        $e = new HttpException(403, "'update' denied on App\\Actions\\RackActions::moveUnit().", 'forbidden');

        $response = (new ExceptionHandler())->handle($e, Request::get('/'));
        $body = json_decode($response->body(), true);

        expect($response->status)->toBe(403)
            ->and($body['error']['code'])->toBe('forbidden')
            ->and($response->body())->not->toContain('RackActions')
            ->and($response->body())->not->toContain('moveUnit')
            ->and($body['error']['digest'])->not->toBeEmpty();
    });

    it('turns a declaration bug into a 500 that says nothing about it', function () {
        $e = new RoutingException('App\\Pages\\Show::__invoke() returned string; a loader returns a Data object.');

        $response = (new ExceptionHandler())->handle($e, Request::get('/'));

        expect($response->status)->toBe(500)
            ->and(json_decode($response->body(), true)['error']['code'])->toBe('internal_error')
            ->and($response->body())->not->toContain('App\\Pages\\Show')
            ->and($response->body())->not->toContain('Data object');
    });

    it('hands the throwable and its digest to the log, which the client never sees', function () {
        $seen = [];
        $handler = new ExceptionHandler(function (Throwable $e, string $digest) use (&$seen): void {
            $seen[] = [$e->getMessage(), $digest];
        });

        $response = $handler->handle(new HttpException(404, 'No App\\Models\\Rack with the given id.'), Request::get('/'));

        expect($seen)->toHaveCount(1)
            ->and($seen[0][0])->toContain('App\\Models\\Rack')
            ->and($seen[0][1])->toBe(json_decode($response->body(), true)['error']['digest']);
    });

    it('keeps validation errors, which are the answer', function () {
        $response = (new ExceptionHandler())->handle(
            new ValidationException(['position' => ['must be at most 42']]),
            Request::get('/'),
        );

        expect($response->status)->toBe(422)
            ->and(json_decode($response->body(), true)['errors'])->toHaveKey('position');
    });
});

// #[PublicAccess] was declared, referenced by nothing but the docblock of the
// middleware that claimed to honour it, and the middleware 401'd regardless.
describe('public entry points', function () {
    $entryPoints = static fn (Request $r): ?array => match ($r->path) {
        '/_action/MixedAccessActions.status' => [A\MixedAccessActions::class, 'status'],
        '/_action/MixedAccessActions.rename' => [A\MixedAccessActions::class, 'rename'],
        default => null,
    };

    it('lets an anonymous caller reach one that opts out', function () use ($entryPoints) {
        $middleware = new RequireAuthentication(new AuthContext(), $entryPoints);

        $response = $middleware->handle(
            Request::post('/_action/MixedAccessActions.status'),
            static fn (Request $r): Response => Response::make(200, 'ran'),
        );

        expect($response->status)->toBe(200);
    });

    it('still refuses one that does not', function () use ($entryPoints) {
        $middleware = new RequireAuthentication(new AuthContext(), $entryPoints);

        expect(fn () => $middleware->handle(
            Request::post('/_action/MixedAccessActions.rename'),
            static fn (Request $r): Response => Response::make(200, 'ran'),
        ))->toThrow(HttpException::class, 'Authentication required');
    });

    // An unknown name is not a public one, and saying "no such action" to an
    // anonymous caller would be an enumeration oracle.
    it('treats an unknown entry point as not public', function () use ($entryPoints) {
        $middleware = new RequireAuthentication(new AuthContext(), $entryPoints);

        expect(fn () => $middleware->handle(
            Request::post('/_action/Nope.nothing'),
            static fn (Request $r): Response => Response::make(200, 'ran'),
        ))->toThrow(HttpException::class, 'Authentication required');
    });

    // Without a lookup, nothing is public: the default direction matters.
    it('is closed when no lookup is supplied', function () {
        $middleware = new RequireAuthentication(new AuthContext());

        expect(fn () => $middleware->handle(
            Request::post('/_action/MixedAccessActions.status'),
            static fn (Request $r): Response => Response::make(200, 'ran'),
        ))->toThrow(HttpException::class, 'Authentication required');
    });
});

// The handler took a Request and never used it, so a browser hitting a 404
// page got a JSON body.
describe('error responses are negotiated', function () {
    it('answers HTML to a browser', function () {
        $request = new Request('GET', '/nope', ['accept' => 'text/html,application/xhtml+xml']);

        $response = (new ExceptionHandler())->handle(new HttpException(404, 'No Rack with that id.'), $request);

        expect($response->header('content-type'))->toContain('text/html')
            ->and($response->body())->toContain('404')
            ->and($response->body())->not->toContain('Rack', 'the message rules still hold on an error page');
    });

    it('answers JSON to an endpoint caller', function () {
        $request = new Request('POST', '/_action/x', ['accept' => 'application/json']);

        $response = (new ExceptionHandler())->handle(new HttpException(403, 'denied'), $request);

        expect($response->header('content-type'))->toBe('application/json');
    });

    // A client that states no preference is more likely to be one of the two
    // endpoints than a person with a browser.
    it('defaults to JSON when nothing is stated', function () {
        $response = (new ExceptionHandler())->handle(new HttpException(500, 'boom'), Request::get('/'));

        expect($response->header('content-type'))->toBe('application/json');
    });
});

final readonly class PrecognitionMisconfiguredRequest extends Tinvar\Validation\FormRequest
{
    // ignoreSubject on an action that resolves no model: the author's bug.
    public function __construct(#[Tinvar\Validation\Rules\Unique(Tinvar\Tests\Orm\Fixtures\Site::class, 'code', ignoreSubject: true)] public string $code) {}
}

final class PrecognitionMisconfiguredActions
{
    #[Tinvar\Attributes\Action, Tinvar\Attributes\NoAuthorization(reason: 'a fixture')]
    public function save(PrecognitionMisconfiguredRequest $request): Tinvar\Actions\ActionResult
    {
        return Tinvar\Actions\ActionResult::ok();
    }
}
