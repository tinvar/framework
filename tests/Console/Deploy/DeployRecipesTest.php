<?php

declare(strict_types=1);

/**
 * Every deploy recipe held to golden files (ADR 0156): what `deploy:init`
 * writes for each platform, and the steps it prints, byte for byte.
 *
 *     TINVAR_UPDATE_GOLDEN=1 vendor/bin/pest packages/framework/tests/Console/Deploy
 *
 * writes the files again from the recipes; read the diff before you
 * commit it.
 */

namespace Tinvar\Tests\Console\Deploy;

use Tinvar\Console\Deploy\ContainerLayout;
use Tinvar\Console\Deploy\DeployApp;
use Tinvar\Console\Deploy\DeployInitCommand;
use Tinvar\Console\Deploy\DeployTarget;
use Tinvar\Console\Deploy\Recipe;
use Tinvar\Http\TrustedProxies;
use Tinvar\Mail\MailProvider;
use Tinvar\Orm\DatabaseDriver;

const SECRETS = '/home/you/.local/state/tinvar/my-app-2cab79174cf3';

/** @return array<string, array{DeployTarget, DeployApp}> */
function cases(): array
{
    return [
        'fly' => [DeployTarget::Fly, new DeployApp('my-app', secrets: SECRETS)],
        'fly-postgres' => [DeployTarget::Fly, new DeployApp('my-app', database: DatabaseDriver::Postgres, domain: 'https://app.example.com', mail: [MailProvider::Resend], secrets: SECRETS, appAddresses: ['66.241.124.10', '2a09:8280:1::4c:1'], front: 'cloudflare')],
        'render' => [DeployTarget::Render, new DeployApp('my-app', secrets: SECRETS)],
        'render-postgres' => [DeployTarget::Render, new DeployApp('my-app', database: DatabaseDriver::Postgres, mail: [MailProvider::Postmark], secrets: SECRETS, front: 'cloudflare')],
        'railway' => [DeployTarget::Railway, new DeployApp('my-app', secrets: SECRETS)],
        'railway-mysql' => [DeployTarget::Railway, new DeployApp('my-app', database: DatabaseDriver::Mysql, domain: 'https://app.example.com', mail: [MailProvider::Ses], secrets: SECRETS)],
        'vps-systemd' => [DeployTarget::VpsSystemd, new DeployApp('my-app', domain: 'https://app.example.com', secrets: SECRETS)],
        'vps-compose' => [DeployTarget::VpsCompose, new DeployApp('my-app', domain: 'https://app.example.com', secrets: SECRETS)],
        'vps-compose-two' => [DeployTarget::VpsCompose, new DeployApp('my-app', layout: ContainerLayout::Two, database: DatabaseDriver::Postgres, domain: 'https://app.example.com', mail: [MailProvider::Smtp], secrets: SECRETS, podTargets: true)],
    ];
}

/**
 * Each file a recipe writes, its steps as steps.txt and its secrets as secrets.txt.
 *
 * @return array<string, string>
 */
function rendered(Recipe $recipe): array
{
    $files = [];
    foreach ($recipe->files as $file) {
        $files[$file->path] = $file->contents;
    }
    $files['steps.txt'] = implode("\n", $recipe->steps) . "\n";
    $files['secrets.txt'] = implode("\n", array_map(static fn ($s): string => "{$s->variable} {$s->extension}", $recipe->secrets)) . "\n";

    return $files;
}

it('writes each platform\'s recipe as its golden files say', function (DeployTarget $target, DeployApp $app, string $case) {
    $golden = __DIR__ . "/golden/{$case}";
    $files = rendered($target->recipe()->write($app));

    if (getenv('TINVAR_UPDATE_GOLDEN') === '1') {
        exec('rm -rf ' . escapeshellarg($golden));
        foreach ($files as $path => $contents) {
            @mkdir(\dirname("{$golden}/{$path}"), 0o755, true);
            file_put_contents("{$golden}/{$path}", $contents);
        }
    }

    $kept = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($golden, \FilesystemIterator::SKIP_DOTS)) as $file) {
        \assert($file instanceof \SplFileInfo);
        $kept[substr($file->getPathname(), \strlen($golden) + 1)] = (string) file_get_contents($file->getPathname());
    }
    ksort($kept);
    ksort($files);

    expect(array_keys($files))->toBe(array_keys($kept));
    foreach ($files as $path => $contents) {
        expect($contents)->toBe($kept[$path], "{$case}/{$path} differs from its golden file: TINVAR_UPDATE_GOLDEN=1 writes it again");
    }
})->with(static function (): array {
    $with = [];
    foreach (cases() as $case => [$target, $app]) {
        $with[$case] = [$target, $app, $case];
    }

    return $with;
});

it('puts no secret in a file it writes, and pins proxies only where one proves itself with the secret', function () {
    foreach (cases() as $case => [$target, $app]) {
        $recipe = $target->recipe()->write($app);
        foreach ($recipe->files as $file) {
            expect($file->contents)->not->toMatch('/(TINVAR_APP_KEY|TINVAR_PROBE_TOKEN|TINVAR_TRUSTED_PROXY_SECRET|PASSWORD)\s*[=:]\s*["\']?[A-Za-z0-9+\/]{20,}/', "{$case}: {$file->path}");
        }
        $all = implode("\n", array_map(static fn ($f): string => $f->contents, $recipe->files)) . implode("\n", $recipe->steps);
        expect($all)->toContain('TINVAR_TRUSTED_PROXIES');
        // A range is refused at boot without the secret (ADR 0134): where
        // one is pinned, the deployment generates the secret.
        $variables = array_map(static fn ($s): string => $s->variable, $recipe->secrets);
        if (preg_match('/TINVAR_TRUSTED_PROXIES[ =:"\']+([0-9][0-9a-f.:\/,]*)/', $all, $pinned) === 1) {
            expect($variables)->toContain('TINVAR_TRUSTED_PROXY_SECRET');
            // And the list is one the boot takes, with a secret.
            expect(TrustedProxies::of(explode(',', $pinned[1]), [], str_repeat('s', 40))->isEmpty())->toBeFalse();
        }
    }
});

it('runs two containers only where the platform and the image can', function () {
    expect(static fn () => DeployTarget::Fly->recipe()->write(new DeployApp('my-app', layout: ContainerLayout::Two, secrets: SECRETS, podTargets: true)))
        ->toThrow(\InvalidArgumentException::class, 'Fly.io runs the one-container layout')
        ->and(static fn () => DeployTarget::VpsCompose->recipe()->write(new DeployApp('my-app', layout: ContainerLayout::Two, secrets: SECRETS)))
        ->toThrow(\InvalidArgumentException::class, "pod-php and pod-renderer targets");
});

it('puts Cloudflare in front only where the platform keeps X-Forwarded-For', function () {
    expect(static fn () => DeployTarget::Railway->recipe()->write(new DeployApp('my-app', secrets: SECRETS, front: 'cloudflare')))
        ->toThrow(\InvalidArgumentException::class, 'replaces X-Forwarded-For')
        ->and(static fn () => new DeployApp('my-app', front: 'fastly'))->toThrow(\InvalidArgumentException::class, '--front is cloudflare');
});

it('refuses a name, an address or a domain a platform would not take', function () {
    expect(static fn () => new DeployApp('My App'))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => new DeployApp('my-app', domain: 'http://app.example.com'))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => new DeployApp('my-app', appAddresses: ['fly.dev']))->toThrow(\InvalidArgumentException::class);
});

it('writes the files, generates the secrets outside the project, and leaves a file of yours alone', function () {
    $work = sys_get_temp_dir() . '/tinvar-deploy-' . bin2hex(random_bytes(4));
    mkdir("{$work}/my-app", 0o755, true);
    $env = ['XDG_STATE_HOME' => "{$work}/state", 'APP_DATABASE_DRIVER' => 'sqlite'];
    $out = '';
    $err = '';
    $command = new DeployInitCommand("{$work}/my-app", $env, static function (string $t) use (&$out): void {
        $out .= $t;
    }, static function (string $t) use (&$err): void {
        $err .= $t;
    });

    try {
        expect($command->run(['vps-compose', '--domain=https://app.example.com']))->toBe(0)
            ->and(is_file("{$work}/my-app/deploy/vps-compose/compose.yaml"))->toBeTrue()
            ->and($out)->toContain('generated the trusted proxy secret (TINVAR_TRUSTED_PROXY_SECRET) in ' . $work . '/state/tinvar/my-app-');
        $secrets = glob("{$work}/state/tinvar/*") ?: [];
        expect($secrets)->toHaveCount(3);
        foreach ($secrets as $secret) {
            expect(fileperms($secret) & 0o777)->toBe(0o600)
                ->and($out)->not->toContain(trim((string) file_get_contents($secret)));
        }

        file_put_contents("{$work}/my-app/deploy/vps-compose/Caddyfile", "mine\n");
        expect($command->run(['vps-compose', '--domain=https://app.example.com']))->toBe(1)
            ->and($err)->toContain('deploy/vps-compose/Caddyfile is already there')
            ->and(file_get_contents("{$work}/my-app/deploy/vps-compose/Caddyfile"))->toBe("mine\n")
            ->and($command->run(['vps-compose', '--domain=https://app.example.com', '--force']))->toBe(0)
            ->and($command->run(['heroku']))->toBe(64);
    } finally {
        exec('rm -rf ' . escapeshellarg($work));
    }
});
