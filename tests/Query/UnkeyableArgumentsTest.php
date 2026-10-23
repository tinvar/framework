<?php

declare(strict_types=1);

/**
 * Identical calls in a tick collapse into one, keyed by their arguments as
 * JSON. Some values the socket carries have no JSON form -- NaN, infinity,
 * bytes that are not UTF-8 -- and one of them failed the whole tick: every
 * other call's result was lost with it.
 */

namespace Tinvar\Tests\Query\UnkeyableArguments;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Data\Data;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final readonly class Echoed extends Data
{
    public function __construct(public string $value) {}
}

#[Query(name: 'unkeyable.scale'), NoAuthorization(reason: 'argument keying is what is under test')]
final class Scale
{
    public function __invoke(float $factor): Echoed
    {
        return new Echoed('factor ' . $factor);
    }
}

#[Query(name: 'unkeyable.name'), NoAuthorization(reason: 'argument keying is what is under test')]
final class Name
{
    public function __invoke(string $name): Echoed
    {
        return new Echoed('name');
    }
}

#[Query(name: 'unkeyable.ping'), NoAuthorization(reason: 'argument keying is what is under test')]
final class Ping
{
    public function __invoke(): Echoed
    {
        return new Echoed('pong');
    }
}

it('fails only the call whose argument has no JSON form', function (string $query, array $args) {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new QueryRegistry();
    $registry->registerAll([Scale::class, Name::class, Ping::class]);
    $runner = new QueryRunner($registry, new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate());

    $results = $runner->run([
        ['id' => 0, 'name' => 'unkeyable.ping', 'args' => []],
        ['id' => 1, 'name' => $query, 'args' => $args],
    ], ['unkeyable.ping', 'unkeyable.scale', 'unkeyable.name']);

    expect($results[0]['value'] ?? null)->toBe(['value' => 'pong'])
        ->and($results[1]['error']['code'] ?? null)->toBe(QueryError::BAD_ARGUMENTS);
})->with([
    'infinity' => ['unkeyable.scale', ['factor' => INF]],
    'NaN' => ['unkeyable.scale', ['factor' => NAN]],
    'bytes that are not UTF-8' => ['unkeyable.name', ['name' => "\xff\xfe"]],
]);

it('fails only that call when the infinity arrived over the socket', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new QueryRegistry();
    $registry->registerAll([Scale::class, Ping::class]);
    $runner = new QueryRunner($registry, new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate());

    // What the renderer writes for query('unkeyable.scale', {factor: Infinity}).
    $codec = new MessagePackCodec();
    $wire = $codec->encodeFrame(['t' => Frame::QUERY, 'q' => [
        ['id' => 0, 'name' => 'unkeyable.ping', 'args' => []],
        ['id' => 1, 'name' => 'unkeyable.scale', 'args' => ['factor' => INF]],
    ]]);
    $offset = 0;
    $calls = Frame::readQueryBatch($codec->decodeFrame($wire, $offset) ?? []);

    $results = $runner->run($calls, ['unkeyable.ping', 'unkeyable.scale']);

    expect($results[0])->toHaveKey('value')
        ->and($results[1]['error']['code'] ?? null)->toBe(QueryError::BAD_ARGUMENTS);
});
