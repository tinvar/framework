<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy;

use Tinvar\Console\Prompts;
use Tinvar\Console\Secrets\SecretFiles;
use Tinvar\Mail\MailProvider;
use Tinvar\Orm\DatabaseDriver;
use Tinvar\Support\StateFile;

/**
 * `tinvar deploy:init <target>` (ADR 0155): one platform's deployment,
 * written into the application -- the platform's config with the proxies
 * its edge connects from, the probes, the database and mail it uses -- and
 * the secrets it needs generated into files outside the project, then the
 * steps that move them into the platform's secret store, each reading its
 * file so no value is printed or typed.
 *
 *     bin/tinvar deploy:init fly
 *     bin/tinvar deploy:init vps-compose --layout=two --domain=https://shop.example.com
 *
 * Asked for a target where a person is there and none is given. A file
 * already there with other contents is left alone unless --force: it may
 * be yours.
 */
final readonly class DeployInitCommand
{
    public const string USAGE = 'deploy:init <fly|render|railway|vps-systemd|vps-compose> [--layout=one|two] [--domain=https://HOST] [--name=NAME] [--front=cloudflare] [--app-ips=ADDRESS,...] [--force]';

    /**
     * @param string $root the application
     * @param array<string, string> $env the environment its database and mail are read from
     * @param \Closure(string): void $out
     * @param \Closure(string): void $err
     */
    public function __construct(
        private string $root,
        private array $env,
        private \Closure $out,
        private \Closure $err,
        private string $console = 'bin/tinvar',
    ) {}

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $options = ['layout' => 'one', 'domain' => null, 'name' => null, 'app-ips' => null, 'front' => null];
        $force = false;
        $target = null;
        foreach ($arguments as $argument) {
            if ($argument === '--force') {
                $force = true;
            } elseif (preg_match('/^--(layout|domain|name|app-ips|front)=(.*)\z/', $argument, $m) === 1) {
                $options[$m[1]] = $m[2];
            } elseif (!str_starts_with($argument, '-') && $target === null) {
                $target = $argument;
            } else {
                ($this->err)('Usage: ' . $this->console . ' ' . self::USAGE . "\n");

                return 64;
            }
        }

        $target ??= Prompts::canAsk()
            ? (string) Prompts::select('Where does the app deploy?', DeployTarget::options(), default: DeployTarget::Fly->value)
            : null;
        $chosen = $target === null ? null : DeployTarget::tryFrom($target);
        if ($chosen === null) {
            ($this->err)(($target === null ? 'Name a target' : "'{$target}' is not a target") . ': fly, render, railway, vps-systemd or vps-compose. Usage: ' . $this->console . ' ' . self::USAGE . "\n");

            return 64;
        }
        $layout = ContainerLayout::tryFrom((string) $options['layout']);
        if ($layout === null) {
            ($this->err)("--layout is one or two.\n");

            return 64;
        }

        try {
            $app = $this->app($options, $layout);
            $recipe = $chosen->recipe()->write($app);
        } catch (\InvalidArgumentException $e) {
            ($this->err)($e->getMessage() . "\n");

            return 64;
        }

        $refused = [];
        foreach ($recipe->files as $file) {
            $path = "{$this->root}/{$file->path}";
            if (is_file($path) && file_get_contents($path) !== $file->contents && !$force) {
                $refused[] = $file->path;
            }
        }
        if ($refused !== []) {
            ($this->err)(implode(', ', $refused) . " is already there, with other contents. Run with --force to write over it, after keeping what is yours.\n");

            return 1;
        }
        foreach ($recipe->files as $file) {
            $path = "{$this->root}/{$file->path}";
            $same = is_file($path) && file_get_contents($path) === $file->contents;
            if (!$same) {
                if (!is_dir(\dirname($path))) {
                    mkdir(\dirname($path), 0o755, true);
                }
                file_put_contents($path, $file->contents);
            }
            ($this->out)(($same ? 'unchanged ' : 'wrote     ') . "{$file->path}\n");
        }

        foreach ($recipe->secrets as $secret) {
            $file = $app->secretFile($secret);
            try {
                $done = SecretFiles::ensure($secret->variable, $file, []);
            } catch (\RuntimeException $e) {
                ($this->err)("{$secret->what}: {$e->getMessage()}\n");

                return 1;
            }
            ($this->out)(($done === 'generated' ? 'generated ' : 'kept      ') . "{$secret->what} ({$secret->variable}) in {$file}\n");
        }

        ($this->out)("\nNext, for {$chosen->title()}:\n\n  " . implode("\n  ", $recipe->steps) . "\n");

        return 0;
    }

    /**
     * The application as the recipe needs it: named for its directory
     * unless --name says, its database and mail as its environment says,
     * its secrets beside its dev key in this user's state directory.
     *
     * @param array{layout: string, domain: string|null, name: string|null, app-ips: string|null, front: string|null} $options
     */
    private function app(array $options, ContainerLayout $layout): DeployApp
    {
        $real = realpath($this->root) ?: $this->root;
        $directory = StateFile::directory($this->env === [] ? null : $this->env)
            ?? throw new \InvalidArgumentException('No state directory to keep the deployment\'s secrets in, outside the project: set HOME or XDG_STATE_HOME.');
        $name = $options['name'] ?? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($real))), '-');
        $dockerfile = is_file("{$this->root}/Dockerfile") ? (string) file_get_contents("{$this->root}/Dockerfile") : '';
        $mail = [];
        foreach (array_filter(array_map(trim(...), explode(',', strtolower($this->env['TINVAR_MAIL_PROVIDER'] ?? '')))) as $provider) {
            $mail[] = MailProvider::tryFrom($provider) ?? throw new \InvalidArgumentException("TINVAR_MAIL_PROVIDER names '{$provider}', which is no provider: smtp, ses, postmark, mailgun, resend or sendgrid.");
        }

        return new DeployApp(
            name: $name,
            layout: $layout,
            database: DatabaseDriver::tryFrom(strtolower($this->env['APP_DATABASE_DRIVER'] ?? 'sqlite')) ?? DatabaseDriver::Sqlite,
            domain: $options['domain'] === null || $options['domain'] === '' ? null : rtrim($options['domain'], '/'),
            mail: $mail,
            secrets: "{$directory}/" . StateFile::stem($real),
            podTargets: preg_match('/\bAS\s+pod-php\b/i', $dockerfile) === 1 && preg_match('/\bAS\s+pod-renderer\b/i', $dockerfile) === 1,
            appAddresses: array_values(array_filter(array_map(trim(...), explode(',', (string) $options['app-ips'])))),
            front: $options['front'] === null || $options['front'] === '' ? null : $options['front'],
        );
    }
}
