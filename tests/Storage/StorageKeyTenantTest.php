<?php

declare(strict_types=1);

/**
 * A storage key carries the tenant it was written for, so every tenant's
 * files sit apart under each prefix -- isolated in the store as well as
 * checked by the record and the token that name them -- and a key written
 * before keys did is read as it always was.
 */

use Tinvar\Render\TenantClaim;
use Tinvar\Storage\StorageKey;

it('writes a tenant\'s key under its segment, after the prefix and before the name', function () {
    expect(StorageKey::generate('avatars', 'png', 'acme'))->toMatch('#^avatars/_acme/[a-f0-9]{32}\.png$#')
        ->and(StorageKey::generate('a/b', '', '01900000-0000-7000-8000-000000000000'))->toMatch('#^a/b/_01900000-0000-7000-8000-000000000000/[a-f0-9]{32}$#');
});

it('hashes a tenant whose id is no segment, apart from any id that is', function () {
    $key = StorageKey::generate('avatars', 'png', 'Acme.Corp');

    expect($key)->toMatch('#^avatars/__[a-f0-9]{40}/[a-f0-9]{32}\.png$#')
        ->and(StorageKey::isValid($key))->toBeTrue()
        ->and(StorageKey::tenantSegment('Acme.Corp'))->not->toBe(StorageKey::tenantSegment('acme.corp'));
});

it('writes no segment for no tenant, or with no tenancy', function () {
    expect(StorageKey::generate('avatars', 'png'))->toMatch('#^avatars/[a-f0-9]{32}\.png$#')
        ->and(StorageKey::generate('avatars', 'png', TenantClaim::NONE))->toMatch('#^avatars/[a-f0-9]{32}\.png$#');
});

it('fits a prefix of eight segments and a tenant\'s', function () {
    expect(StorageKey::isValid(StorageKey::generate('a/b/c/d/e/f/g/h', 'csv', 'acme')))->toBeTrue();
});

it('refuses a key with two tenants\' segments, or one where its name should be', function () {
    expect(StorageKey::isValid('avatars/_acme/_globex/abc.png'))->toBeFalse()
        ->and(StorageKey::isValid('avatars/_acme'))->toBeFalse()
        ->and(StorageKey::isValid('_acme/abc.png'))->toBeTrue()
        ->and(StorageKey::isValidPrefix('_acme'))->toBeFalse();
});

it('serves a key to its own tenant alone, and one written before keys named one as it always was', function () {
    $acme = StorageKey::generate('avatars', 'png', 'acme');

    expect(StorageKey::tenantSegmentOf($acme))->toBe('_acme')
        ->and(StorageKey::belongsTo($acme, 'acme'))->toBeTrue()
        ->and(StorageKey::belongsTo($acme, 'globex'))->toBeFalse()
        ->and(StorageKey::belongsTo($acme, ''))->toBeFalse()
        ->and(StorageKey::belongsTo($acme, TenantClaim::NONE))->toBeFalse()
        ->and(StorageKey::belongsTo('avatars/4f1c00000000000000000000000000aa.png', 'globex'))->toBeTrue()
        ->and(StorageKey::tenantSegmentOf('avatars/4f1c00000000000000000000000000aa.png'))->toBeNull();
});
