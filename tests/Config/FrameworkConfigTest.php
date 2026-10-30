<?php

declare(strict_types=1);

/**
 * The framework's own variables on #[Config] (ADR 0085): accepting what
 * the code that read them accepted, refusing what it refused, naming the
 * variable and never the value, and the live hub's keys as Secrets.
 */

namespace Tinvar\Tests\Config\FrameworkConfig;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Config\Secret;
use Tinvar\Jobs\StopBudget;
use Tinvar\Live\HubSettings;
use Tinvar\Render\RendererUser;
use Tinvar\Support\BuildProfile;

/**
 * Runs $work with the variables set as given, each put back after.
 *
 * @param array<string, string> $env
 */
function withEnv(array $env, \Closure $work): mixed
{
    $before = [];
    foreach ($env as $name => $value) {
        $before[$name] = getenv($name);
        putenv("{$name}={$value}");
    }

    try {
        return $work();
    } finally {
        foreach ($before as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        Configs::forget();
    }
}

/** @param array<string, string> $env */
function refusal(array $env): ConfigException
{
    try {
        Configs::load(FrameworkConfig::class, $env);
    } catch (ConfigException $e) {
        return $e;
    }

    throw new \LogicException('Not refused.');
}

it('reads nothing set as the framework always defaulted', function () {
    $config = Configs::load(FrameworkConfig::class, []);

    expect($config->dev)->toBeNull()
        ->and($config->rendererUserName())->toBeNull()
        ->and($config->liveSubscriberKey)->toBeNull()
        ->and($config->liveHubUrl)->toBeNull()
        ->and(StopBudget::parse($config->stopBudget))->toBe(StopBudget::DEFAULT);
});

it('turns dev mode on at 1 and at nothing else, as DevMode read it', function () {
    expect(withEnv(['TINVAR_DEV' => '1'], static fn (): bool => FrameworkConfig::load()->devMode()))->toBeTrue()
        // Off, as before -- not refused, which a bool key would have made it.
        ->and(withEnv(['TINVAR_DEV' => 'true'], static fn (): bool => FrameworkConfig::load()->devMode()))->toBeFalse()
        ->and(withEnv(['TINVAR_DEV' => '0'], static fn (): bool => FrameworkConfig::load()->devMode()))->toBeFalse();
});

it('refuses dev mode beside a production marker', function () {
    expect(static fn () => withEnv(['TINVAR_DEV' => '1', 'APP_ENV' => 'production'], static fn (): bool => FrameworkConfig::load()->devMode()))
        ->toThrow(\RuntimeException::class, 'APP_ENV=production');
});

it('reads the profile in any case and with spaces, and holds test to the environment', function () {
    expect(withEnv(['TINVAR_PROFILE' => ' Production '], static fn (): string => FrameworkConfig::load()->buildProfile()))->toBe(BuildProfile::PRODUCTION)
        ->and(withEnv(['TINVAR_PROFILE' => 'TEST', 'TINVAR_ENV' => 'testing'], static fn (): string => FrameworkConfig::load()->buildProfile()))->toBe(BuildProfile::TEST)
        ->and(static fn () => withEnv(['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing', 'TINVAR_DEV' => '1'], static fn (): string => FrameworkConfig::load()->buildProfile()))
        ->toThrow(\RuntimeException::class, 'dev mode');

    // Staging (ADR 0090): only where TINVAR_ENV says so, as BuildProfile holds it.
    expect(withEnv(['TINVAR_PROFILE' => 'Staging', 'TINVAR_ENV' => 'staging'], static fn (): string => FrameworkConfig::load()->buildProfile()))->toBe(BuildProfile::STAGING)
        ->and(static fn () => withEnv(['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'testing'], static fn (): string => FrameworkConfig::load()->buildProfile()))
        ->toThrow(\RuntimeException::class, 'TINVAR_ENV does not say staging');
});

it('refuses a profile, a stop budget and a hub URL by variable, quoting none of them', function () {
    $e = refusal([
        'TINVAR_PROFILE' => 'staging-secret',
        'TINVAR_STOP_BUDGET' => '90s-secret',
        'TINVAR_LIVE_HUB_URL' => 'http://user:hunter2@hub.example/.well-known/mercure',
    ]);

    expect($e->problems)->toHaveCount(3)
        ->and($e->getMessage())->toContain('TINVAR_PROFILE')
        ->and($e->getMessage())->toContain('TINVAR_STOP_BUDGET')
        ->and($e->getMessage())->toContain('TINVAR_LIVE_HUB_URL')
        ->and($e->getMessage())->not->toContain('secret')
        ->and($e->getMessage())->not->toContain('hunter2')
        ->and($e->getMessage())->not->toContain('hub.example');
});

it('holds a stop budget to its range where the worker reads it', function () {
    expect(withEnv(['TINVAR_STOP_BUDGET' => '120'], static fn (): int => StopBudget::fromEnvironment()))->toBe(120)
        ->and(static fn () => withEnv(['TINVAR_STOP_BUDGET' => '3'], static fn (): int => StopBudget::fromEnvironment()))
        ->toThrow(\InvalidArgumentException::class);
});

it('keeps the live hub\'s keys as Secrets, and the hub signs with them', function () {
    $key = 'an-application-key-of-thirty-two-bytes!!';
    $config = Configs::load(FrameworkConfig::class, ['TINVAR_LIVE_SUBSCRIBER_KEY' => 'sub-key-from-the-env', 'TINVAR_LIVE_PUBLISHER_KEY' => 'pub-key-from-the-env']);

    expect($config->liveSubscriberKey)->toBeInstanceOf(Secret::class)
        ->and(print_r($config, true))->not->toContain('sub-key-from-the-env')
        ->and(json_encode($config))->not->toContain('pub-key-from-the-env');

    $settings = withEnv(
        ['TINVAR_LIVE_SUBSCRIBER_KEY' => 'sub-key-from-the-env', 'TINVAR_LIVE_AUDIENCE' => 'https://live.example/.well-known/mercure'],
        static fn (): HubSettings => HubSettings::resolve($key),
    );
    expect($settings->subscriberKey)->toBe('sub-key-from-the-env')
        // Unset: derived from the application key, as before.
        ->and($settings->publisherKey)->toBe(HubSettings::derive($key)->publisherKey)
        ->and($settings->audience)->toBe('https://live.example/.well-known/mercure');
});

it('names the renderers\' user from the config, blank as unset', function () {
    expect(withEnv(['TINVAR_RENDERER_USER' => '   '], static fn (): ?RendererUser => RendererUser::fromEnvironment()))->toBeNull()
        ->and(static fn () => withEnv(['TINVAR_RENDERER_USER' => '0'], static fn (): ?RendererUser => RendererUser::fromEnvironment()))
        ->toThrow(\InvalidArgumentException::class, 'root');
});

it('is listed by the shop beside its own classes, none reading a variable another does', function () {
    expect(\Shop\Kernel::CONFIGS)->toContain(FrameworkConfig::class)
        ->and(Configs::check(\Shop\Kernel::CONFIGS))->toBe([]);
});

// ADR 0111: the node CA, staging's key and the FrankenPHP binary, read as
// the rest are -- once, by variable, staging's key a Secret.
it('reads the node CA, staging\'s key and the FrankenPHP binary, refusing each by variable alone', function () {
    $config = Configs::load(FrameworkConfig::class, [
        'TINVAR_NODE_CA' => '/etc/tinvar/node-ca.pem',
        'TINVAR_STAGING_APP_KEY' => str_repeat('k', 32),
        'TINVAR_FRANKENPHP' => '/usr/local/bin/frankenphp',
    ]);
    $e = refusal(['TINVAR_NODE_CA' => 'relative/ca.pem', 'TINVAR_STAGING_APP_KEY' => 'short-staging-secret']);

    expect($config->nodeCa)->toBe('/etc/tinvar/node-ca.pem')
        ->and($config->stagingAppKey)->toBeInstanceOf(Secret::class)
        ->and($config->stagingAppKey?->reveal())->toBe(str_repeat('k', 32))
        ->and($config->frankenphp)->toBe('/usr/local/bin/frankenphp')
        ->and($e->problems)->toBe([
            'TINVAR_NODE_CA must be an absolute path.',
            'TINVAR_STAGING_APP_KEY must be at least 32 bytes.',
        ])
        ->and($e->getMessage())->not->toContain('short-staging-secret')
        ->and($e->getMessage())->not->toContain('relative');
});
