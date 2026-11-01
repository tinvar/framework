<?php

declare(strict_types=1);

/**
 * A query's scalar arguments, coerced to the types its __invoke declares,
 * as an action's are (Validator::coerceParameter()).
 *
 * Backed enums are the norm for a closed set (ADR 0145), and a renderer, a
 * tool call and a service call all send a value as JSON: a string for a
 * string-backed case, a number -- or the digits of one, from a form or a
 * URL -- for an int-backed one or an int. Spread straight into the body,
 * each was a TypeError, the call a `query_failed` for the caller's input;
 * coerced, a good value reaches the body as its type, and a bad one is
 * refused as bad arguments -- a 422 over HTTP, `invalid` to a tool.
 */

namespace Tinvar\Tests\Query\ScalarArguments;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Calls\CallRunner;
use Tinvar\Calls\Operation;
use Tinvar\Data\Data;
use Tinvar\Http\Endpoints\QueryEndpoint;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

enum Status: string
{
    case Open = 'open';
    case Closed = 'closed';
}

enum Priority: int
{
    case Low = 1;
    case High = 2;
}

final readonly class Seen extends Data
{
    public function __construct(public string $value) {}
}

#[Query(name: 'typed.list'), NoAuthorization(reason: 'argument coercion is what is under test')]
final class Listing
{
    public function __invoke(Status $status, Priority $priority, int $page = 1, ?Status $also = null, float $ratio = 1.0, bool $all = false): Seen
    {
        return new Seen(\sprintf(
            '%s %s %d %s %s %s',
            $status->name,
            $priority->name,
            $page,
            $also?->name ?? '-',
            var_export($ratio, true),
            var_export($all, true),
        ));
    }
}

#[Query(name: 'typed.tags'), NoAuthorization(reason: 'argument coercion is what is under test')]
final class Tagged
{
    /** @param array<array-key, mixed> $tags */
    public function __invoke(array $tags = []): Seen
    {
        return new Seen((string) \count($tags));
    }
}

function runner(): QueryRunner
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new QueryRegistry();
    $registry->register(Listing::class);
    $registry->register(Tagged::class);

    return new QueryRunner($registry, new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate());
}

/**
 * @param array<string, mixed> $args
 * @return array{id: int, value?: mixed, error?: array{code: string, digest: string, message?: string}}
 */
function call(array $args): array
{
    return runner()->run([['id' => 0, 'name' => 'typed.list', 'args' => $args]], ['typed.list'])[0];
}

describe('a render\'s call', function () {
    it('hands the body each argument as the type it declares', function (array $args, string $seen) {
        expect(call($args)['value'] ?? call($args))->toBe(['value' => $seen]);
    })->with([
        'as JSON sends them' => [['status' => 'open', 'priority' => 2, 'page' => 3], 'Open High 3 - 1.0 false'],
        'as digits' => [['status' => 'closed', 'priority' => '1', 'page' => '7', 'ratio' => '0.5', 'all' => 'true'], 'Closed Low 7 - 0.5 true'],
        'an optional enum named' => [['status' => 'open', 'priority' => 1, 'also' => 'closed'], 'Open Low 1 Closed 1.0 false'],
        'an optional enum sent as null' => [['status' => 'open', 'priority' => 1, 'also' => null], 'Open Low 1 - 1.0 false'],
    ]);

    it('refuses a value that is not one of the type\'s as bad arguments, not a failure', function (array $args) {
        expect(call($args)['error']['code'] ?? null)->toBe(QueryError::BAD_ARGUMENTS);
    })->with([
        'no such case' => [['status' => 'pending', 'priority' => 1]],
        'no such int case' => [['status' => 'open', 'priority' => 3]],
        'an int case spelled as a word' => [['status' => 'open', 'priority' => 'high']],
        'not a whole number' => [['status' => 'open', 'priority' => 1, 'page' => '2.5']],
        'an array' => [['status' => ['open'], 'priority' => 1]],
        'null for a required enum' => [['status' => null, 'priority' => 1]],
    ]);

    // A list is the caller's to get right as much as a number is: a string
    // spread into `array $tags` was a TypeError in the body, a failure.
    it('refuses a value an array parameter has no place for as bad arguments', function (mixed $tags, ?string $code) {
        $result = runner()->run([['id' => 0, 'name' => 'typed.tags', 'args' => ['tags' => $tags]]], ['typed.tags'])[0];

        expect($result['error']['code'] ?? null)->toBe($code);
    })->with([
        'a list' => [['a', 'b'], null],
        'a string' => ['a', QueryError::BAD_ARGUMENTS],
        'a number' => [3, QueryError::BAD_ARGUMENTS],
    ]);

    it('dedupes two calls that name one value two ways', function () {
        $results = runner()->run([
            ['id' => 1, 'name' => 'typed.list', 'args' => ['status' => 'open', 'priority' => 2]],
            ['id' => 2, 'name' => 'typed.list', 'args' => ['status' => 'open', 'priority' => '2']],
        ], ['typed.list']);

        expect($results[0]['value'] ?? null)->toBe(['value' => 'Open High 1 - 1.0 false'])
            ->and($results[1]['value'] ?? null)->toBe(['value' => 'Open High 1 - 1.0 false']);
    });
});

describe('a call over HTTP', function () {
    it('answers a good value and refuses a bad one with a 422', function () {
        $endpoint = new QueryEndpoint(runner(), static fn (Request $r): array => ['typed.list']);
        $post = static fn (array $args) => $endpoint->handle(
            new Request('POST', '/_query/typed.list', ['host' => 'shop.test', 'content-type' => 'application/json']),
            json_encode(['args' => $args], JSON_THROW_ON_ERROR),
        );

        $good = $post(['status' => 'open', 'priority' => '2']);
        $bad = $post(['status' => 'open', 'priority' => 9]);

        expect($good->status)->toBe(200, $good->body())
            ->and(json_decode($good->body(), true)['value'] ?? null)->toBe(['value' => 'Open High 1 - 1.0 false'])
            ->and($bad->status)->toBe(422)
            ->and(json_decode($bad->body(), true)['error']['code'] ?? null)->toBe(QueryError::BAD_ARGUMENTS);
    });
});

describe('a tool\'s or a service\'s call', function () {
    it('answers a good value and calls a bad one invalid', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $calls = new CallRunner(new ActionRunner(new ActionRegistry(), $orm, new AllowAllGate(), new \Tinvar\Validation\Validator($orm)), runner());
        $operation = new Operation('typed.list', Operation::QUERY, Listing::class, null, [
            'status' => ['type' => 'string'], 'priority' => ['type' => 'integer'], 'page' => ['type' => 'integer'],
        ]);

        $good = $calls->run($operation, ['status' => 'closed', 'priority' => 1, 'page' => '4']);
        $bad = $calls->run($operation, ['status' => 'closed', 'priority' => '1x']);

        expect($good->status)->toBe('ok')
            ->and($good->value)->toBe(['value' => 'Closed Low 4 - 1.0 false'])
            ->and($bad->status)->toBe('invalid');
    });
});
