<?php

declare(strict_types=1);

/**
 * A `__proto__` map key at the data boundary.
 *
 * Map keys are often user data -- a settings map saved from a form, a jsonb
 * column. The renderer's MessagePack decoder throws on this one key, so letting
 * it cross failed every render of that data for good; the json-lines decoder
 * used to turn it into a prototype instead.
 */

namespace Tinvar\Tests\Data\ProtoKey;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\Encoder;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final readonly class SettingsData extends Data
{
    /** @param array<string, mixed> $settings */
    public function __construct(public string $team, public array $settings) {}
}

#[Query(name: 'settings.show'), NoAuthorization(reason: 'fixture: exercises the data boundary, not access control')]
final class ShowSettings
{
    public function __invoke(string $key): SettingsData
    {
        return new SettingsData('ops', ['theme' => 'dark', $key => ['isAdmin' => true]]);
    }
}

it('refuses a __proto__ map key wherever it sits', function (mixed $value) {
    expect(fn () => (new Encoder())->encode($value))
        ->toThrow(DataBoundaryException::class, '__proto__');
})->with([
    'top-level map' => [['__proto__' => 1]],
    'inside a Data field' => [new SettingsData('ops', ['theme' => 'dark', '__proto__' => ['isAdmin' => true]])],
    'deep in a list' => [[['a' => ['__proto__' => null]]]],
]);

it('still encodes a map whose keys only resemble it', function () {
    $value = ['proto' => 1, '__proto' => 2, '__PROTO__' => 3, 'constructor' => 4];

    expect((new Encoder())->encode($value))->toBe($value);
});

it('fails only the query call whose result carries the key', function () {
    $fixture = new Fixture(racks: 0, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new QueryRegistry(static fn (string $class): object => new $class());
    $registry->register(ShowSettings::class);
    $runner = new QueryRunner($registry, $orm, new AllowAllGate());

    $results = $runner->run([
        ['id' => 0, 'name' => 'settings.show', 'args' => ['key' => '__proto__']],
        ['id' => 1, 'name' => 'settings.show', 'args' => ['key' => 'layout']],
    ], ['settings.show']);

    expect($results[0]['error']['code'])->toBe(QueryError::BAD_RESULT)
        ->and($results[0])->not->toHaveKey('value')
        ->and($results[1]['value']['settings'])->toBe(['theme' => 'dark', 'layout' => ['isAdmin' => true]]);
});
