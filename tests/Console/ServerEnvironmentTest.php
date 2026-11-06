<?php

declare(strict_types=1);

/**
 * What `serve` and `dev` hand FrankenPHP alone (ADR 0178): the console's
 * own serverEnvironment where it gives one, and otherwise the live hub's
 * keys, derived from the app key the console's key file holds. The dev
 * Caddyfile's hub refuses to start without them, and an app made before
 * its console passed them had no way to serve a page in dev.
 */

namespace Tinvar\Tests\Console\ServerEnvironment;

use Tinvar\Console\AppConsole;
use Tinvar\Console\Secrets\SecretFiles;
use Tinvar\Discovery\DiscoveredClasses;
use Tinvar\Http\FileBuildStore;
use Tinvar\Live\HubSettings;
use Tinvar\Packages\PackagePolicy;
use Tinvar\Support\AppKey;

/**
 * @param (\Closure(): array<string, string>)|null $serverEnvironment
 * @param (\Closure(): ?string)|null $keyFile
 */
function console(?\Closure $serverEnvironment = null, ?\Closure $keyFile = null): AppConsole
{
    return new AppConsole(
        root: __DIR__ . '/../../../../examples/shop',
        console: __FILE__,
        namespace: 'Shop',
        classes: static fn (): DiscoveredClasses => new DiscoveredClasses(),
        packages: static fn (): PackagePolicy => new PackagePolicy(),
        buildStore: static fn (): FileBuildStore => new FileBuildStore(sys_get_temp_dir() . '/unused-builds.json'),
        database: static fn (): string => ':memory:',
        migrations: sys_get_temp_dir(),
        projectRoot: __DIR__ . '/../../../..',
        serverEnvironment: $serverEnvironment,
        keyFile: $keyFile,
    );
}

beforeEach(function () {
    $this->state = sys_get_temp_dir() . '/tinvar-server-env-' . bin2hex(random_bytes(4));
    mkdir($this->state, 0o700);
    $this->key = SecretFiles::fresh();
    file_put_contents("{$this->state}/app.key", $this->key);
    chmod("{$this->state}/app.key", 0o600);
    $this->saved = getenv('TINVAR_APP_KEY');
    putenv('TINVAR_APP_KEY');
});

afterEach(function () {
    @unlink("{$this->state}/app.key");
    rmdir($this->state);
    putenv($this->saved === false ? 'TINVAR_APP_KEY' : "TINVAR_APP_KEY={$this->saved}");
});

it('hands FrankenPHP the hub keys derived from the key file, where the console gives none', function () {
    $file = "{$this->state}/app.key";
    $environment = console(keyFile: static fn (): string => $file)->serverEnvironment();

    expect($environment)->not->toBeNull();
    \assert($environment !== null);
    $expected = HubSettings::resolve(AppKey::application('TINVAR_APP_KEY', $file))->environment();
    expect($environment())->toBe($expected)
        ->and($environment())->toHaveKeys([HubSettings::SUBSCRIBER_ENV, HubSettings::PUBLISHER_ENV])
        // The keys alone, never the app key they are derived from.
        ->and(array_values($environment()))->not->toContain($this->key);
});

it('takes the key TINVAR_APP_KEY gives over the file', function () {
    $given = SecretFiles::fresh();
    putenv("TINVAR_APP_KEY={$given}");

    $environment = console(keyFile: static fn (): string => '/nonexistent/app.key')->serverEnvironment();

    \assert($environment !== null);
    expect($environment())->toBe(HubSettings::resolve(AppKey::application('TINVAR_APP_KEY', '/nonexistent/app.key'))->environment());
});

it('hands on the console\'s own, where it gives one', function () {
    $own = static fn (): array => ['SHOP_ONLY' => 'x'];

    expect(console($own, static fn (): string => '/unused')->serverEnvironment())->toBe($own);
});

it('hands nothing where there is no app key to derive from', function () {
    expect(console()->serverEnvironment())->toBeNull();
});
