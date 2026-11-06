<?php

declare(strict_types=1);

/**
 * The skeleton a new app is made from (ADR 0082), held to the template it
 * was copied from: the same pins as the framework's own CI and image, and
 * the same refusals. bin/skeleton-proof makes an app of it and runs it;
 * this is what can be read off its files without making one.
 */

namespace Tinvar\Tests\Packages\Skeleton;

const ROOT = __DIR__ . '/../../../..';

function read(string $path): string
{
    $contents = file_get_contents(ROOT . '/' . $path);
    expect($contents)->toBeString();

    return (string) $contents;
}

/** @return array<string, string> NAME => value, of every NAME=value line */
function assignments(string $file): array
{
    preg_match_all('/^([A-Z][A-Z0-9_]*)=(.*)$/m', read($file), $matches, \PREG_SET_ORDER);

    return array_column($matches, 2, 1);
}

it('builds with the Composer, Node and checksums the framework\'s CI pins', function () {
    $ours = assignments('packages/skeleton/docker/setup');
    $ci = assignments('.github/ci/setup');

    foreach (['COMPOSER_VERSION', 'COMPOSER_SHA256', 'NODE_VERSION'] as $name) {
        expect($ours[$name] ?? null)->toBe($ci[$name] ?? 'missing');
    }
    preg_match_all('/NODE_SHA256=([0-9a-f]{64})/', read('.github/ci/setup'), $ciNode);
    preg_match_all('/NODE_SHA256=([0-9a-f]{64})/', read('packages/skeleton/docker/setup'), $ourNode);
    expect($ourNode[1])->toBe($ciNode[1]);
});

it('builds from the bases, FrankenPHP and tini the shop\'s image pins', function () {
    $pins = static function (string $file): array {
        preg_match_all('/(?:FROM \S+@sha256:[0-9a-f]{64}|_sha256=[0-9a-f]{64}|releases\/download\/v[0-9.]+)/', read($file), $found);
        $found = array_values(array_unique($found[0]));
        sort($found);

        return $found;
    };

    $ours = $pins('packages/skeleton/Dockerfile');
    expect($ours)->not->toBe([])
        ->and(array_diff($ours, $pins('examples/shop/Dockerfile')))->toBe([]);
});

it('compiles the PDO drivers of a server database into both PHPs of the image', function () {
    // `tinvar new --database=postgres|mysql` deploys this image: FrankenPHP's
    // static binary has every driver, and the image's own CLI -- migrate,
    // queue:work under serve -- must have them too (the audit of 2026-11-03).
    foreach (['packages/skeleton/docker/setup', 'packages/skeleton/Dockerfile'] as $file) {
        $contents = read($file);
        expect($contents)->toMatch('/docker-php-ext-install [^\n]*\bpdo_pgsql\b/', $file)
            ->toMatch('/docker-php-ext-install [^\n]*\bpdo_mysql\b/', $file)
            ->toMatch('/for extension in [^\n]*\bpdo_pgsql\b/', $file)
            ->toMatch('/for extension in [^\n]*\bpdo_mysql\b/', $file);
    }
    // libpq, which pdo_pgsql links, stays in the runtime once the headers go.
    expect(read('packages/skeleton/Dockerfile'))->toContain('apt-mark manual libpq5');
});

it('keeps every refusal of the shop\'s container', function () {
    $compose = read('packages/skeleton/compose.yaml');
    $dockerfile = read('packages/skeleton/Dockerfile');

    expect($compose)->toContain('read_only: true')
        ->toContain('cap_drop: [ALL]')
        ->toContain('cap_add: [SETUID, SETGID, SETPCAP]')
        ->toContain('no-new-privileges:true')
        // Each secret a compose secret, a file in secrets/ the entrypoint
        // reads; none from .env, which docker inspect would show.
        ->toContain('TINVAR_APP_KEY_FILE: /run/secrets/app_key')
        ->toContain('TINVAR_PROBE_TOKEN_FILE: /run/secrets/probe_token')
        // A range pinned is a secret required (ADR 0134).
        ->toContain('TINVAR_TRUSTED_PROXY_SECRET_FILE: /run/secrets/trusted_proxy_secret')
        ->toContain('secrets: [app_key, probe_token, trusted_proxy_secret]')
        ->toContain('file: ./secrets/app_key')
        ->not->toContain('${APP_KEY')
        ->not->toContain('${APP_PROBE_TOKEN')
        ->not->toContain('${APP_TRUSTED_PROXY_SECRET')
        ->toContain('TINVAR_PROBE_TRUST_LOOPBACK: "0"')
        ->toContain('"127.0.0.1:8080:8080"')
        ->and($dockerfile)->toContain('TINVAR_ENV=production')
        ->toContain('TINVAR_PROBE_TRUST_LOOPBACK=0')
        ->toContain('composer install --no-dev')
        ->toContain('npm ci --ignore-scripts')
        ->toContain('ENTRYPOINT ["/usr/local/lib/tinvar/entrypoint"]')
        // Where compose mounts the secrets: the renderers' user cannot pass.
        ->toContain('install -d -o 0 -g tinvar-app -m 0710 /run/secrets')
        ->not->toContain('TINVAR_APP_KEY=');
});

it('ships no secret, and keeps secrets and state out of git and the image', function () {
    foreach (assignments('packages/skeleton/.env.example') as $name => $value) {
        expect($value)->toBe('', "{$name} has a value in .env.example");
    }

    foreach (['.gitignore', '.dockerignore'] as $ignore) {
        expect(read("packages/skeleton/{$ignore}"))->toContain('.env')->toContain('*.key')->toContain('.sqlite')->toContain('secrets');
    }
    expect(glob(ROOT . '/packages/skeleton/{,*/,*/*/}*.{key,sqlite,pem}', \GLOB_BRACE) ?: [])->toBe([])
        ->and(file_exists(ROOT . '/packages/skeleton/.env'))->toBeFalse();
});

it('runs no install script and nothing it fetched when an app is made', function () {
    $composer = json_decode(read('packages/skeleton/composer.json'), true);
    \assert(\is_array($composer));

    expect($composer['scripts']['post-create-project-cmd'] ?? null)->toBe(['@php bin/tinvar setup'])
        ->and(read('packages/skeleton/bin/tinvar'))->toContain("'ci', '--ignore-scripts'")
        ->and(read('packages/skeleton/bin/tinvar'))->not->toContain("'install', '--ignore-scripts'");
});

// ADR 0138: the skeleton ships its lock, and setup installs from it with
// npm ci -- but not while it would fetch @tinvar/mail, @tinvar/react or
// @tinvar/renderer from a registry before the project has published them
// there. As shipped before a release publishes them, the check refuses it
// for exactly that and nothing else. A release publishes them, then takes
// @tinvar/* out of the skeleton's $unpublished and ships them in the lock
// from npm's registry (ADR 0174): the check then refuses nothing.
it('ships a lock its own check refuses only for leaving unpublished @tinvar/* to a registry', function () {
    $kernel = read('packages/skeleton/app/Kernel.php');
    $unpublished = str_contains($kernel, "unpublished: ['@tinvar/*']");
    expect(read('packages/skeleton/bin/tinvar'))->toContain('new SupplyChain($root, Kernel::packages())->check()');

    $policy = new \Tinvar\Packages\PackagePolicy(
        installScripts: ['fsevents' => 'As the skeleton allows it.'],
        registries: [\Tinvar\Packages\PackagePolicy::NPM_REGISTRY, 'https://design.chopper.dev/packages/'],
        unpublished: $unpublished ? ['@tinvar/*'] : [],
    );
    // composer.lock is written by create-project, before setup runs.
    $violations = array_values(array_diff(new \Tinvar\Packages\SupplyChain(ROOT . '/packages/skeleton', $policy)->check(), ['composer.lock is missing.']));
    $lock = json_decode(read('packages/skeleton/package-lock.json'), true);
    \assert(\is_array($lock) && \is_array($lock['packages']));
    $tinvar = array_values(array_filter(array_keys($lock['packages']), static fn (string $path): bool => str_contains($path, '@tinvar/')));

    if ($unpublished) {
        // Each of the three it takes from inside the app (ADR 0138): the mail kit
        // its account emails are drawn with (ADR 0153), the client and the renderer.
        expect($violations)->toHaveCount(3)
            ->and($violations[0])->toStartWith('package.json depends on @tinvar/mail, which PackagePolicy::$unpublished says no registry serves yet')
            ->and($violations[1])->toStartWith('package.json depends on @tinvar/react, which PackagePolicy::$unpublished says no registry serves yet')
            ->and($violations[2])->toStartWith('package.json depends on @tinvar/renderer, which PackagePolicy::$unpublished says no registry serves yet')
            ->and($tinvar)->toBe([]);

        return;
    }

    expect($violations)->toBe([])
        ->and($tinvar)->toEqualCanonicalizing(['node_modules/@tinvar/mail', 'node_modules/@tinvar/react', 'node_modules/@tinvar/renderer']);
    foreach (['mail', 'react', 'renderer'] as $directory) {
        $entry = $lock['packages']["node_modules/@tinvar/{$directory}"];
        $manifest = json_decode(read("packages/{$directory}/package.json"), true);
        \assert(\is_array($entry) && \is_array($manifest));
        expect($entry['version'] ?? null)->toBe($manifest['version'])
            ->and($entry['resolved'] ?? null)->toBe("https://registry.npmjs.org/@tinvar/{$directory}/-/{$directory}-{$manifest['version']}.tgz")
            ->and($entry['integrity'] ?? '')->toStartWith('sha512-');
    }
});

/** The Caddyfile's handler for PHP, comments and blank lines taken out. */
function phpHandler(string $file): string
{
    preg_match('/\n\t\theader \{\n.*?\n\t\tphp \{\n.*?\n\t\t\}\n/s', read($file), $found);
    $lines = array_filter(
        explode("\n", $found[0] ?? ''),
        static fn (string $line): bool => trim($line) !== '' && !str_starts_with(trim($line), '#'),
    );

    return implode("\n", $lines);
}

// ADR 0081: no worker writes to a client. The skeleton's Caddyfile holds
// every response whole and hands stored files over exactly as the shop's
// does, from a storage directory of its own, and its check says so.
it('serves PHP behind the shop\'s intercept block, which its check holds it to', function () {
    $ours = phpHandler('packages/skeleton/Caddyfile');

    expect($ours)->toContain('intercept {')
        ->and($ours)->toBe(str_replace('SHOP_STORAGE', 'APP_STORAGE', phpHandler('examples/shop/Caddyfile')))
        ->and(read('packages/skeleton/Caddyfile'))->toContain('write_idle 8s')
        // Its check is the framework's application console's, which holds
        // the app's own Caddyfile, as the workspace's console holds the shop's.
        ->and(read('packages/skeleton/bin/tinvar'))->toContain('new AppConsole(')
        ->and(read('packages/framework/src/Console/AppConsole.php'))->toContain('CaddyfileRules::check("{$this->root}/Caddyfile")')
        ->and(read('bin/tinvar'))->toContain('new AppConsole(')
        ->and(read('packages/skeleton/.env.example'))->toContain('# APP_STORAGE=');
});
