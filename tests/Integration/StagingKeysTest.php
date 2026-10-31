<?php

declare(strict_types=1);

/**
 * Staging and production, handed the same TINVAR_APP_KEY, believe nothing
 * the other mints (ADR 0090): the shop's key is bound to the profile it
 * serves, and every cookie, token, signed URL, hub key and encrypted value
 * is made from that.
 */

namespace Tinvar\Tests\Integration\StagingKeys;

use Shop\Kernel;
use Tinvar\Live\HubSettings;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Support\Encrypter;
use Tinvar\Support\Signer;
use Tinvar\Tenancy\SignedUrls;
use Tinvar\Http\Request;

const SHARED = 'one-key-copied-into-both-environments-by-mistake';

/**
 * The shop's key under $env, with TINVAR_APP_KEY the same in both.
 *
 * @param array<string, string|null> $env
 */
function keyUnder(array $env): string
{
    $env += ['TINVAR_APP_KEY' => SHARED];
    $before = [];
    foreach ($env as $name => $value) {
        $before[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }
    try {
        return Kernel::appKey();
    } finally {
        foreach ($before as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

function production(): string
{
    return keyUnder(['TINVAR_PROFILE' => null, 'TINVAR_ENV' => null, 'TINVAR_TEST_RUN' => null]);
}

function staging(): string
{
    return keyUnder(['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging', 'APP_ENV' => null, 'TINVAR_TEST_RUN' => null]);
}

it('keeps production\'s key as configured and gives staging its own', function () {
    expect(production())->toBe(SHARED)
        ->and(staging())->not->toBe(SHARED);
});

it('refuses a staging session cookie in production, and a production one in staging', function () {
    $fromStaging = Kernel::newSession(staging(), signedIn: true);
    $fromProduction = Kernel::newSession(production(), signedIn: true);

    expect(Kernel::sessionFrom($fromStaging, staging()))->not->toBeNull()
        ->and(Kernel::sessionFrom($fromStaging, production()))->toBeNull()
        ->and(Kernel::sessionFrom($fromProduction, production()))->not->toBeNull()
        ->and(Kernel::sessionFrom($fromProduction, staging()))->toBeNull();
});

it('refuses a token signed in one for every purpose the shop signs', function (string $purpose) {
    $minted = new Signer(staging(), $purpose)->sign('session|user|tenant|panel|build');

    expect(new Signer(production(), $purpose)->verify('session|user|tenant|panel|build', $minted))->toBeFalse()
        ->and(new Signer(staging(), $purpose)->verify('session|user|tenant|panel|build', $minted))->toBeTrue();
})->with([FragmentToken::PURPOSE, RouteToken::PURPOSE, 'csrf-token', 'history-key', 'upload-ticket', 'file-token', 'email-link', 'mail-unsubscribe', 'job-payload', 'live-token']);

it('refuses a signed URL from the other', function () {
    $url = new SignedUrls(new Signer(production(), SignedUrls::PURPOSE))->sign('/invoices/7', 'tenant-1', now: 1_000);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    /** @var array<string, string> $query */
    $request = new Request('GET', (string) parse_url($url, PHP_URL_PATH), [], $query);

    expect(new SignedUrls(new Signer(production(), SignedUrls::PURPOSE))->tenantOf($request, now: 1_001))->toBe('tenant-1')
        ->and(new SignedUrls(new Signer(staging(), SignedUrls::PURPOSE))->tenantOf($request, now: 1_001))->toBeNull();
})->skip(!class_exists(SignedUrls::class), 'tinvar/tenancy is not installed: the framework\'s suite alone runs without it (ADR 0056).');

it('derives the live hub\'s keys apart', function () {
    $production = HubSettings::derive(production());
    $staging = HubSettings::derive(staging());

    expect($staging->subscriberKey)->not->toBe($production->subscriberKey)
        ->and($staging->publisherKey)->not->toBe($production->publisherKey);
});

it('does not decrypt in staging what production encrypted', function () {
    $sealed = new Encrypter(production())->encrypt('a production secret', 'customers.notes');

    expect(new Encrypter(staging())->decrypt($sealed, 'customers.notes'))->toBeNull()
        ->and(new Encrypter(production())->decrypt($sealed, 'customers.notes'))->toBe('a production secret');
});
