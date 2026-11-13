<?php

declare(strict_types=1);

/**
 * Every deploy recipe held to golden files (ADR 0157): what `deploy:init`
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
        'fly-postgres' => [DeployTarget::Fly, new DeployApp('my-app', database: DatabaseDriver::Postgres, domain: 'https://app.example.com', mail: [MailProvider::Resend], secrets: SECRETS, front: 'cloudflare')],
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

// Audit 2026-11-13 M5: the Caddy a recipe puts in front read a request for
// as long as its client liked and passed any body on, a byte at a time, to
// a worker. It now holds the request to deadlines and a ceiling, and buffers
// a small body before the app sees it (ADR 0207).
it('holds the Caddy it puts in front to read deadlines and a body ceiling, and buffers a small body whole', function () {
    foreach (['vps-compose' => 'deploy/vps-compose/Caddyfile', 'vps-systemd' => 'deploy/vps-systemd/Caddyfile'] as $case => $path) {
        [$target, $app] = cases()[$case];
        $caddyfile = rendered($target->recipe()->write($app))[$path];

        expect($caddyfile)->toMatch('/\n\t\ttimeouts \{\n\t\t\tread_header 10s\n\t\t\tread_body 60s\n/')
            ->and($caddyfile)->toMatch('/\n\trequest_body \{\n\t\tmax_size 11MiB\n\t\}\n/')
            ->and($caddyfile)->toContain("\t\trequest_buffers 1MiB\n");
    }
});

// The skeleton refuses to boot in production without APP_URL, which its
// account links and passkeys are built on, and APP_MAIL_FROM.
it('sets the origin the app builds its links on, and says what else production refuses to boot without', function () {
    foreach (cases() as $case => [$target, $app]) {
        $recipe = $target->recipe()->write($app);
        $all = implode("\n", array_map(static fn ($f): string => $f->contents, $recipe->files)) . "\n" . implode("\n", $recipe->steps);

        expect(str_contains($all, 'APP_URL'))->toBeTrue("{$case} sets no APP_URL")
            ->and(str_contains(implode("\n", $recipe->steps), 'APP_MAIL_FROM'))->toBeTrue("{$case} says nothing of APP_MAIL_FROM");
    }
});

it('runs two containers only where the platform and the image can', function () {
    expect(static fn () => DeployTarget::Fly->recipe()->write(new DeployApp('my-app', layout: ContainerLayout::Two, secrets: SECRETS, podTargets: true)))
        ->toThrow(\InvalidArgumentException::class, 'Fly.io runs the one-container layout')
        ->and(static fn () => DeployTarget::VpsCompose->recipe()->write(new DeployApp('my-app', layout: ContainerLayout::Two, secrets: SECRETS)))
        ->toThrow(\InvalidArgumentException::class, "pod-php and pod-renderer targets");
});

it('puts Cloudflare in front only where the platform\'s edge has a range to pin', function () {
    expect(static fn () => DeployTarget::Railway->recipe()->write(new DeployApp('my-app', secrets: SECRETS, front: 'cloudflare')))
        ->toThrow(\InvalidArgumentException::class, 'documents no address range its edge connects')
        ->and(static fn () => new DeployApp('my-app', front: 'fastly'))->toThrow(\InvalidArgumentException::class, '--front is cloudflare');
});

// Cloudflare's Transform Rule sends the secret for a request a Cloudflare
// Worker -- anyone's -- sends to the domain too, and the Worker writes
// X-Forwarded-For as it likes. So the rule names the client itself, from
// ip.src, and the boot reads that in place of X-Forwarded-For.
it('has Cloudflare in front name the client from ip.src, which the boot reads in place of X-Forwarded-For', function (DeployTarget $target, string $edge) {
    $recipe = $target->recipe()->write(new DeployApp('my-app', secrets: SECRETS, front: 'cloudflare'));
    $all = implode("\n", array_map(static fn ($f): string => $f->contents, $recipe->files)) . "\n" . implode("\n", $recipe->steps);
    preg_match('/TINVAR_TRUSTED_PROXIES[\s=:"\']+(?:value: ")?([0-9][0-9a-f.:\/,]*)/', $all, $pinned);

    expect($all)->toMatch('/TINVAR_TRUSTED_PROXY_CLIENT\W+(value: )?"?header"?/')
        ->and(implode("\n", $recipe->steps))->toContain('Set dynamic X-Tinvar-Client to ip.src')
        ->and(implode("\n", $recipe->steps))->toContain('Set dynamic X-Tinvar-Host to http.host');

    $secret = str_repeat('s', 40);
    $config = \Tinvar\Config\Configs::load(\Tinvar\Config\FrameworkConfig::class, [
        'TINVAR_TRUSTED_PROXIES' => $pinned[1] ?? '',
        'TINVAR_TRUSTED_PROXY_SECRET' => $secret,
        'TINVAR_TRUSTED_PROXY_CLIENT' => 'header',
    ]);
    // A Worker's request through the edge: the X-Forwarded-For it wrote
    // names someone else, Cloudflare's ip.src the Worker.
    $worker = new \Tinvar\Http\Request('GET', '/', [
        'x-forwarded-for' => '198.51.100.66, 104.16.0.9',
        'x-forwarded-host' => 'admin.example.com',
        TrustedProxies::CLIENT_HEADER => '2a06:98c0:3600::103',
        TrustedProxies::HOST_HEADER => 'app.example.com',
        TrustedProxies::SECRET_HEADER => $secret,
    ], remoteAddress: $edge);

    // And the X-Forwarded-Host it wrote names another host; Cloudflare's
    // http.host the one it was sent to.
    expect($config->proxies()->clientAddress($worker))->toBe('2a06:98c0:3600::103')
        ->and($config->proxies()->host($worker))->toBe('app.example.com');
})->with([
    'fly' => [DeployTarget::Fly, '172.16.4.5'],
    'render' => [DeployTarget::Render, '10.20.30.40'],
]);

// The client and the host are read from Cloudflare's own headers alone, and
// X-Forwarded-For never: Cloudflare's ranges and the app's own addresses,
// pinned only to walk it, are not pinned. The peer is the platform's edge,
// and the secret proves it was sent through Cloudflare.
it('pins only the platform\'s edge behind a front, which the secret alone proves', function (DeployTarget $target, string $range, string $edge) {
    $recipe = $target->recipe()->write(new DeployApp('my-app', secrets: SECRETS, front: 'cloudflare'));
    $all = implode("\n", array_map(static fn ($f): string => $f->contents, $recipe->files)) . "\n" . implode("\n", $recipe->steps);
    preg_match('/TINVAR_TRUSTED_PROXIES[\s=:"\']+(?:value: ")?([0-9][0-9a-f.:\/,]*)/', $all, $pinned);

    expect($pinned[1] ?? null)->toBe($range)
        ->and($all)->not->toContain('--app-ips')
        ->and($all)->not->toContain('fly ips list');

    $secret = str_repeat('s', 40);
    $proxies = \Tinvar\Config\Configs::load(\Tinvar\Config\FrameworkConfig::class, [
        'TINVAR_TRUSTED_PROXIES' => $pinned[1],
        'TINVAR_TRUSTED_PROXY_SECRET' => $secret,
        'TINVAR_TRUSTED_PROXY_CLIENT' => 'header',
    ])->proxies();
    $request = static fn (?string $proof): \Tinvar\Http\Request => new \Tinvar\Http\Request('GET', '/', [
        'host' => 'my-app.example',
        TrustedProxies::CLIENT_HEADER => '203.0.113.9',
        TrustedProxies::HOST_HEADER => 'app.example.com',
        ...($proof === null ? [] : [TrustedProxies::SECRET_HEADER => $proof]),
    ], remoteAddress: $edge);

    // Through Cloudflare: its client and host. Straight to the platform,
    // with no secret or a wrong one: the edge itself, and the Host it saw.
    expect($proxies->clientAddress($request($secret)))->toBe('203.0.113.9')
        ->and($proxies->host($request($secret)))->toBe('app.example.com')
        ->and($proxies->clientAddress($request(null)))->toBe($edge)
        ->and($proxies->host($request(str_repeat('x', 40))))->toBe('my-app.example');
})->with([
    'fly' => [DeployTarget::Fly, '172.16.0.0/12', '172.16.4.5'],
    'render' => [DeployTarget::Render, '10.0.0.0/8', '10.20.30.40'],
]);

it('takes no --app-ips: nothing reads the addresses it pinned', function () {
    $work = sys_get_temp_dir() . '/tinvar-deploy-' . bin2hex(random_bytes(4));
    mkdir("{$work}/my-app", 0o755, true);
    $err = '';
    $command = new DeployInitCommand("{$work}/my-app", ['XDG_STATE_HOME' => "{$work}/state"], static function (string $t): void {}, static function (string $t) use (&$err): void {
        $err .= $t;
    });

    try {
        expect($command->run(['fly', '--front=cloudflare', '--app-ips=66.241.124.10']))->toBe(64)
            ->and($err)->toContain('Usage:')
            ->and(DeployInitCommand::USAGE)->not->toContain('--app-ips');
    } finally {
        exec('rm -rf ' . escapeshellarg($work));
    }
});

it('refuses a name, an address or a domain a platform would not take', function () {
    expect(static fn () => new DeployApp('My App'))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => new DeployApp('my-app', domain: 'http://app.example.com'))->toThrow(\InvalidArgumentException::class);
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
