<?php

declare(strict_types=1);

/**
 * The tenant a token is bound to, in each tenancy mode (ADR 0056).
 *
 * With tenancy registered, the tenant itself, exactly as before, so every
 * token minted then still verifies. With none, a value no tenant can be, so
 * a token minted in one mode never verifies in the other. The endpoints'
 * own tests hold each kind of token to it: FragmentEndpointTest,
 * RevalidatorTest, UploadFlowTest, HandoffTest and HandoffConsentTest.
 */

use Tinvar\Http\FragmentSupport;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\TenantClaim;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

function claimOrm(bool $tenancy): Orm
{
    return new Orm(PdoConnection::sqlite(':memory:', 'claim'), new IdentityMap('claim'), $tenancy ? Tenancy::flat() : null);
}

describe('with tenancy registered', function () {
    it('is the tenant, exactly as it was', function () {
        $orm = claimOrm(true);

        expect(TenantClaim::current($orm))->toBe('')
            ->and($orm->actAs('acme', static fn (): string => TenantClaim::current($orm)))->toBe('acme')
            ->and(TenantClaim::of($orm, 'globex'))->toBe('globex')
            ->and(TenantClaim::of($orm, ''))->toBe('');
    });

    // A realm's tenant comes from configuration, not through the Orm, and
    // could otherwise be spelled to look like the no-tenancy claim.
    it('refuses a tenant spelled with a control character', function () {
        expect(fn () => TenantClaim::of(claimOrm(true), TenantClaim::NONE))->toThrow(\LogicException::class, 'control character')
            ->and(fn () => TenantClaim::of(claimOrm(true), "acme\n"))->toThrow(\LogicException::class);
    });
});

describe('with no tenancy registered', function () {
    it('is one value whatever the Orm\'s context names, and no tenant\'s', function () {
        $orm = claimOrm(false);

        expect(TenantClaim::current($orm))->toBe(TenantClaim::NONE)
            ->and($orm->actAs('acme', static fn (): string => TenantClaim::current($orm)))->toBe(TenantClaim::NONE)
            ->and(TenantClaim::of($orm, 'globex'))->toBe(TenantClaim::NONE)
            ->and(TenantClaim::of($orm, ''))->toBe(TenantClaim::NONE);
    });

    it('is a value no tenant can be, in either mode', function () {
        expect(fn () => claimOrm(true)->switchTenant(TenantClaim::NONE))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => claimOrm(false)->switchTenant(TenantClaim::NONE))->toThrow(\InvalidArgumentException::class)
            ->and(TenantClaim::NONE)->not->toBe('');
    });
});

it('is what a fragment minter binds its tokens to, in each mode', function (bool $tenancy, string $claim) {
    $orm = claimOrm($tenancy);
    $signer = new Signer('claim-test-key-of-thirty-two-bytes', FragmentToken::PURPOSE);
    $support = new FragmentSupport(
        signer: $signer,
        builds: new BuildRegistry('b1'),
        render: static fn () => null,
        sessionId: static fn (): string => 'session',
        orm: $orm,
    );

    $token = $orm->actAs('acme', static fn (): string => $support->minterFor(new Request('GET', '/'), 'racks.show', [])->mint('Rack', []));

    expect(FragmentToken::decode($token, $signer)->tenant)->toBe($claim);
})->with([
    'tenancy' => [true, 'acme'],
    'no tenancy' => [false, TenantClaim::NONE],
]);
