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
use Tinvar\Http\Request;
use Tinvar\Render\RendererUser;
use Tinvar\Support\AppKey;
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

it('binds the hub\'s keys from the environment to the profile, once', function () {
    $key = 'an-application-key-of-thirty-two-bytes!!';
    $staging = ['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging'];

    $settings = withEnv(
        ['TINVAR_LIVE_SUBSCRIBER_KEY' => 'sub-key-from-the-env', 'TINVAR_LIVE_PUBLISHER_KEY' => 'pub-key-from-the-env', ...$staging],
        static fn (): HubSettings => HubSettings::resolve($key),
    );
    // What `tinvar serve` hands its workers, in the same variables.
    $handedOn = withEnv([...$settings->environment(), ...$staging], static fn (): HubSettings => HubSettings::resolve($key));

    expect($settings->subscriberKey)->toBe(AppKey::forProfile('sub-key-from-the-env', 'staging'))
        ->and($settings->publisherKey)->toBe(AppKey::forProfile('pub-key-from-the-env', 'staging'))
        ->and($handedOn->subscriberKey)->toBe($settings->subscriberKey)
        ->and($handedOn->publisherKey)->toBe($settings->publisherKey)
        // Staging's handed to production is refused, not believed.
        ->and(static fn () => withEnv(['TINVAR_LIVE_SUBSCRIBER_KEY' => $settings->subscriberKey], static fn (): HubSettings => HubSettings::resolve($key)))
        ->toThrow(\InvalidArgumentException::class, 'bound to the staging profile');
});

// `tinvar serve` derives the keys from the app key when nothing names
// them, and hands them to Caddy and to its workers in the variables a
// configured key is read from: a worker must come to the key the hub
// checks with, under every profile, or no subscription is ever let in.
it('hands the derived hub keys on to the workers as the keys the hub holds, under any profile', function (string $profile) {
    $key = AppKey::forProfile('an-application-key-of-thirty-two-bytes!!', $profile);
    $env = ['TINVAR_PROFILE' => $profile, 'TINVAR_ENV' => $profile === 'test' ? 'testing' : $profile];

    $served = withEnv($env, static fn (): HubSettings => HubSettings::resolve($key));
    $handedOn = withEnv([...$served->environment(), ...$env], static fn (): HubSettings => HubSettings::resolve($key));

    expect($handedOn->subscriberKey)->toBe($served->subscriberKey)
        ->and($handedOn->publisherKey)->toBe($served->publisherKey)
        ->and($served->environment()['TINVAR_LIVE_SUBSCRIBER_KEY'])->toBe($served->subscriberKey);
})->with(['production', 'staging', 'test']);

it('names the renderers\' user from the config, blank as unset', function () {
    expect(withEnv(['TINVAR_RENDERER_USER' => '   '], static fn (): ?RendererUser => RendererUser::fromEnvironment()))->toBeNull()
        ->and(static fn () => withEnv(['TINVAR_RENDERER_USER' => '0'], static fn (): ?RendererUser => RendererUser::fromEnvironment()))
        ->toThrow(\InvalidArgumentException::class, 'root');
});

it('is listed by the shop beside its own classes, none reading a variable another does', function () {
    expect(\Shop\Kernel::classes()->configs)->toContain(FrameworkConfig::class)
        ->and(Configs::check(\Shop\Kernel::classes()->configs))->toBe([]);
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

// ADR 0113: a MySQL dump's copy is made as a user of its own, named by a
// DSN read as a Secret and refused by its variable alone.
it('reads the dumps\' MySQL user as a Secret, refusing any other driver by variable alone', function () {
    $config = Configs::load(FrameworkConfig::class, ['TINVAR_DUMP_MYSQL_DSN' => 'mysql:host=db;user=tinvar_dump;password=hunter2']);
    $e = refusal(['TINVAR_DUMP_MYSQL_DSN' => 'pgsql:host=db;password=hunter2']);

    expect($config->dumpMysqlDsn)->toBeInstanceOf(Secret::class)
        ->and($config->dumpMysqlDsn?->reveal())->toBe('mysql:host=db;user=tinvar_dump;password=hunter2')
        ->and($e->problems)->toBe(['TINVAR_DUMP_MYSQL_DSN must be a mysql: DSN.'])
        ->and($e->getMessage())->not->toContain('hunter2');
});

// ADR 0134: the proxies in front, pinned by network, through which the
// throttles read the client's address -- a malformed list stops the boot.
it('reads the trusted proxies as a list of networks, refusing a malformed one by its place alone', function () {
    $config = Configs::load(FrameworkConfig::class, ['TINVAR_TRUSTED_PROXIES' => '10.89.0.1, fd00::/8', 'TINVAR_TRUSTED_PROXY_SECRET' => str_repeat('k', 40)]);
    $none = Configs::load(FrameworkConfig::class, []);
    $e = refusal(['TINVAR_TRUSTED_PROXIES' => '10.0.0.0/8,proxy.internal']);
    $everyone = refusal(['TINVAR_TRUSTED_PROXIES' => '0.0.0.0/0']);

    expect($config->proxies()->trusts('10.89.0.1'))->toBeTrue()
        ->and($config->proxies()->trusts('fd12::1'))->toBeTrue()
        ->and($config->proxies()->trusts('10.89.0.2'))->toBeFalse()
        ->and($none->proxies()->isEmpty())->toBeTrue()
        ->and($e->problems)->toHaveCount(1)
        ->and($e->problems[0])->toStartWith("TINVAR_TRUSTED_PROXIES's 2nd entry is not a network")
        ->and($e->getMessage())->not->toContain('proxy.internal')
        ->and($everyone->problems[0])->toStartWith("TINVAR_TRUSTED_PROXIES's 1st entry is not a network");
});

// ADR 0134: a range holds more peers than the proxy, so the secret is
// required wherever one is pinned; a single address needs none.
it('stops the boot for a range pinned with no secret, and boots a single address without one', function () {
    $wide = refusal(['TINVAR_TRUSTED_PROXIES' => '192.0.2.7, 10.213.89.0/24']);
    $single = Configs::load(FrameworkConfig::class, ['TINVAR_TRUSTED_PROXIES' => '127.0.0.1, 2001:db8::7/128']);

    expect($wide->problems)->toHaveCount(1)
        ->and($wide->problems[0])->toStartWith("TINVAR_TRUSTED_PROXY_SECRET is required: TINVAR_TRUSTED_PROXIES's 2nd entry is a range wider than one address")
        ->and($single->proxies()->trusts('127.0.0.1'))->toBeTrue();
});

it('holds a pinned proxy to the secret it must send, and refuses one too short or with no proxy', function () {
    $secret = str_repeat('k', 40);
    $config = Configs::load(FrameworkConfig::class, ['TINVAR_TRUSTED_PROXIES' => '10.0.0.0/8', 'TINVAR_TRUSTED_PROXY_SECRET' => $secret]);
    $request = static fn (array $headers): Request => new Request('GET', '/', ['x-forwarded-for' => '198.51.100.1', ...$headers], remoteAddress: '10.1.2.3');
    $short = refusal(['TINVAR_TRUSTED_PROXIES' => '10.0.0.0/8', 'TINVAR_TRUSTED_PROXY_SECRET' => 'short']);
    $alone = refusal(['TINVAR_TRUSTED_PROXY_SECRET' => $secret]);

    expect($config->proxies()->clientAddress($request(['x-tinvar-proxy' => $secret])))->toBe('198.51.100.1')
        ->and($config->proxies()->clientAddress($request([])))->toBe('10.1.2.3')
        ->and($short->problems[0])->toStartWith('TINVAR_TRUSTED_PROXY_SECRET is 32 characters or more')
        ->and($short->getMessage())->not->toContain('short')
        ->and($alone->problems[0])->toContain('pins no proxy');
});
