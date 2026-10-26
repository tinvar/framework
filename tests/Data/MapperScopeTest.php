<?php

declare(strict_types=1);

namespace Tinvar\Tests\Data\Scope;

use Tinvar\Data\Data;
use Tinvar\Support\Lazy;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once __DIR__ . '/../Orm/Fixtures/tenant.php';

final readonly class RunData extends Data
{
    public function __construct(public string $status) {}
}

final readonly class ScriptData extends Data
{
    /** @param Lazy<list<RunData>> $runs */
    public function __construct(public string $name, public Lazy $runs) {}
}

/**
 * A Lazy field is read when the encoder resolves it, after the code that set
 * the scope up has returned. It read under whatever tenant was in force
 * then; it now reads under the one it was mapped in.
 */
it('reads a lazy relation under the tenant it was mapped in', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('scope-test'));
    $acmeScript = $fixture->scripts['acme'][0];

    $data = $orm->actAs('acme', fn (): ScriptData => ScriptData::from($orm->find(Script::class, $acmeScript)));

    // Resolved later, with another tenant active.
    $orm->switchTenant('globex');
    $runs = $data->runs->resolve();

    expect($runs)->not->toBe([])
        ->and($orm->tenant())->toBe('globex');
});

// The seam the mapper uses: the scope in force when captured, re-entered
// around work run later.
it('captures tenant and read-only state for work run later', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('scope-test'));

    $scoped = $orm->actAs('acme', fn (): \Closure => $orm->readOnly(fn (): \Closure => $orm->captureScope()));
    $orm->switchTenant('globex');

    expect($scoped(fn (): array => [$orm->tenant(), $orm->isReadOnly(), $orm->isUnscoped()]))->toBe(['acme', true, false])
        ->and([$orm->tenant(), $orm->isReadOnly()])->toBe(['globex', false]);
});

// acrossTenants() is an explicit escape hatch; deferred work that re-entered
// it later would lift scoping outside the block that opened it.
it('refuses to capture an unscoped context', function () {
    $orm = new Orm((new TenantFixture())->connection, new IdentityMap('scope-test'));

    expect(fn () => $orm->acrossTenants(fn (): \Closure => $orm->captureScope()))
        ->toThrow(\LogicException::class, 'An unscoped read cannot be deferred');
});

it('reads a lazy relation mapped inside acrossTenants() there and then', function () {
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('scope-test'));
    $acmeScript = $fixture->scripts['acme'][0];

    $data = $orm->acrossTenants(fn (): ScriptData => ScriptData::from($orm->find(Script::class, $acmeScript)));

    expect($data->runs->isResolved())->toBeTrue()
        ->and($data->runs->resolve())->not->toBe([]);
});
