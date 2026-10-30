<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\EndpointLimits;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

/**
 * The endpoints table's limits for /_action and /_query, "Body 1 MB (uploads
 * separate), JSON depth 32", and the spec's line under it: "The limits are
 * proposed defaults, configurable per app." The endpoints always took a body
 * size, and the Kernel never passed one; the depth was a constant.
 */
final class Actions
{
    #[Action(name: 'limits.touch'), NoAuthorization(reason: 'a fixture with no model input')]
    public function touch(): ActionResult
    {
        return ActionResult::ok('touched');
    }
}

#[Query(name: 'limits.count'), NoAuthorization(reason: 'a fixture query')]
final class Count
{
    public function __invoke(): int
    {
        return 1;
    }
}

/** @return array{Kernel, AuthContext} */
function kernel(int $maxBodyBytes = Limits::MAX_BODY_BYTES, int $maxJsonDepth = Limits::MAX_JSON_DEPTH): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $auth = new AuthContext();
    $actions = new ActionRegistry();
    $actions->register(Actions::class);
    $queries = new QueryRegistry();
    $queries->register(Count::class);

    return [new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: new RouteRegistry(),
        queries: $queries,
        actions: $actions,
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => ['limits.count'],
        maxBodyBytes: $maxBodyBytes,
        maxJsonDepth: $maxJsonDepth,
    ), $auth];
}

/**
 * POSTs $body to both endpoints as a signed-in visitor's script would, and
 * says what each answered.
 *
 * @param array<string, mixed> $body
 * @param bool $form posted as a form, which PHP parses, rather than as JSON
 * @return array{action: int, query: int}
 */
function post(array $kernel, array $body, bool $form = false): array
{
    [$app, $auth] = $kernel;
    $statuses = [];

    foreach (['action' => '/_action/limits.touch', 'query' => '/_query/limits.count'] as $endpoint => $path) {
        $auth->login(new User('u-1'));
        $request = new Request(
            'POST',
            $path,
            [
                'content-type' => $form ? 'application/x-www-form-urlencoded' : 'application/json',
                'accept' => 'application/json',
                'x-xsrf-token' => 'tok',
            ],
            [],
            $form ? $body : [],
            [VerifyCsrfToken::COOKIE => 'tok'],
        );
        $response = $app->handle(
            $request,
            static fn (): Response => throw new \LogicException('no page here'),
            $form ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
        $statuses[$endpoint] = $response->status;
    }

    return $statuses;
}

/** A value nested $levels arrays deep. */
function nested(int $levels): mixed
{
    $value = 1;
    for ($i = 0; $i < $levels; $i++) {
        $value = [$value];
    }

    return $value;
}

describe('by default', function () {
    it('keeps the table\'s 1 MB body', function () {
        expect(post(kernel(), ['pad' => str_repeat('x', 4096)]))->toBe(['action' => 200, 'query' => 200])
            ->and(post(kernel(), ['pad' => str_repeat('x', Limits::MAX_BODY_BYTES)]))->toBe(['action' => 413, 'query' => 413]);
    });

    // An object holding the value is one level, and PHP's decoder counts the
    // innermost value as one more.
    it('keeps the table\'s depth of 32', function () {
        expect(post(kernel(), ['pad' => nested(30)]))->toBe(['action' => 200, 'query' => 200])
            ->and(post(kernel(), ['pad' => nested(31)]))->toBe(['action' => 400, 'query' => 400]);
    });
});

describe('given to the Kernel', function () {
    it('holds both endpoints to a smaller body', function () {
        $kernel = kernel(maxBodyBytes: 512);

        expect(post($kernel, ['pad' => str_repeat('x', 400)]))->toBe(['action' => 200, 'query' => 200])
            ->and(post($kernel, ['pad' => str_repeat('x', 600)]))->toBe(['action' => 413, 'query' => 413])
            ->and(post($kernel, ['pad' => str_repeat('x', 600)], form: true))->toBe(['action' => 413, 'query' => 413]);
    });

    it('lets both take a larger one, up to the ceiling', function () {
        $body = ['pad' => str_repeat('x', 2 * Limits::MAX_BODY_BYTES)];

        expect(post(kernel(maxBodyBytes: Limits::MAX_BODY_BYTES_CEILING), $body))->toBe(['action' => 200, 'query' => 200]);
    });

    it('holds both endpoints to a shallower depth, in JSON and in a form', function () {
        $kernel = kernel(maxJsonDepth: 4);

        expect(post($kernel, ['pad' => nested(2)]))->toBe(['action' => 200, 'query' => 200])
            ->and(post($kernel, ['pad' => nested(3)]))->toBe(['action' => 400, 'query' => 400])
            ->and(post($kernel, ['pad' => nested(3)], form: true))->toBe(['action' => 200, 'query' => 200])
            ->and(post($kernel, ['pad' => nested(4)], form: true))->toBe(['action' => 400, 'query' => 400]);
    });

    it('lets both take a deeper body, up to the ceiling', function () {
        expect(post(kernel(maxJsonDepth: Limits::MAX_JSON_DEPTH_CEILING), ['pad' => nested(40)]))
            ->toBe(['action' => 200, 'query' => 200]);
    });
});

// A misconfiguration fails the Kernel rather than opening the endpoints: a
// limit of zero read as "none", or PHP_INT_MAX as "unlimited", would take a
// body of any size or depth from anyone who can reach them.
it('refuses a limit that is not positive or is over its ceiling', function (array $limits, string $message) {
    expect(fn () => kernel(...$limits))->toThrow(\InvalidArgumentException::class, $message);
})->with([
    'no body' => [['maxBodyBytes' => 0], 'The endpoint body limit is 0 bytes; it must be from 1 to 8388608'],
    'a negative body' => [['maxBodyBytes' => -1], 'The endpoint body limit is -1 bytes'],
    'a body over the ceiling' => [['maxBodyBytes' => Limits::MAX_BODY_BYTES_CEILING + 1], 'it must be from 1 to 8388608'],
    'an unlimited body' => [['maxBodyBytes' => PHP_INT_MAX], 'it must be from 1 to 8388608'],
    'no depth' => [['maxJsonDepth' => 0], 'The endpoint JSON depth limit is 0; it must be from 2 to 64'],
    'a depth no body fits' => [['maxJsonDepth' => 1], 'The endpoint JSON depth limit is 1; it must be from 2 to 64'],
    'a depth over the ceiling' => [['maxJsonDepth' => Limits::MAX_JSON_DEPTH_CEILING + 1], 'it must be from 2 to 64'],
    'PHP\'s own depth' => [['maxJsonDepth' => 512], 'it must be from 2 to 64'],
]);

// The floor is the shallowest depth a call still gets through at: every
// body is an object, and PHP counts it as a level. One below it refuses
// `{}` itself, so the Kernel refuses it rather than build endpoints that
// answer every call 400.
it('takes a flat body at the shallowest depth it allows', function () {
    expect(Limits::MIN_JSON_DEPTH)->toBe(2)
        ->and(post(kernel(maxJsonDepth: Limits::MIN_JSON_DEPTH), ['pad' => 'x']))->toBe(['action' => 200, 'query' => 200])
        ->and(json_decode('{}', true, Limits::MIN_JSON_DEPTH - 1))->toBeNull()
        ->and(fn () => Limits::decodeJson('{"pad":"x"}', Limits::MAX_BODY_BYTES, 1))->toThrow(\InvalidArgumentException::class, 'depth limit');
});

// Held by the decoder too, so a caller of its own -- not the endpoints -- cannot
// hand it a limit that opens it.
it('refuses a limit over its ceiling to the decoders themselves', function () {
    expect(fn () => Limits::decodeJson('{}', PHP_INT_MAX))->toThrow(\InvalidArgumentException::class, 'body limit')
        ->and(fn () => Limits::form([], Limits::MAX_BODY_BYTES, 512))->toThrow(\InvalidArgumentException::class, 'depth limit');
});
