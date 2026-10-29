<?php

declare(strict_types=1);

/**
 * The shop under the staging profile (ADR 0090): served only from a build
 * made for staging, and a staging build served by nothing else.
 */

namespace Tinvar\Tests\Integration\ShopStagingProfile;

use Shop\Kernel;
use Tinvar\Http\Application;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Testing\Browser\BrowserTests;

/** @param array<string, string|null> $env */
function serving(array $env): Application
{
    $before = [];
    foreach ($env as $name => $value) {
        $before[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }
    try {
        return Kernel::serving();
    } finally {
        foreach ($before as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

function build(string $profile): string
{
    $directory = PrivateDirectory::create('profile');
    mkdir("{$directory}/rsc");
    file_put_contents("{$directory}/rsc/profile.json", json_encode(['format' => 1, 'profile' => $profile], JSON_THROW_ON_ERROR));
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            PrivateDirectory::remove("{$directory}/rsc");
            PrivateDirectory::remove($directory);
        }
    });

    return $directory;
}

const STAGING = ['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging', 'APP_ENV' => null, 'TINVAR_TEST_RUN' => null, 'TINVAR_DEV' => null];

it('serves a staging build under the staging profile, with no test endpoint', function () {
    $application = serving([...STAGING, 'TINVAR_BUILD_DIR' => build('staging')]);

    expect($application)->toBeInstanceOf(Kernel::class)
        ->and($application)->not->toBeInstanceOf(BrowserTests::class);
});

it('refuses a production build under the staging profile', function () {
    serving([...STAGING, 'TINVAR_BUILD_DIR' => build('production')]);
})->throws(\RuntimeException::class, 'was made for production');

it('refuses a staging build under the production profile', function () {
    serving(['TINVAR_PROFILE' => null, 'TINVAR_ENV' => null, 'TINVAR_TEST_RUN' => null, 'TINVAR_DEV' => null, 'TINVAR_BUILD_DIR' => build('staging')]);
})->throws(\RuntimeException::class, 'was made with --profile=staging');

it('refuses a staging environment that forgot its profile', function () {
    serving(['TINVAR_PROFILE' => null, 'TINVAR_ENV' => 'staging', 'TINVAR_TEST_RUN' => null, 'TINVAR_DEV' => null, 'TINVAR_BUILD_DIR' => build('production')]);
})->throws(\RuntimeException::class, 'TINVAR_ENV=staging, and the profile is production');
