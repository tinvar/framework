<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy\Recipes;

use Tinvar\Console\Deploy\DeployApp;
use Tinvar\Console\Deploy\DeployRecipe;
use Tinvar\Console\Deploy\DeployTarget;
use Tinvar\Console\Deploy\Recipe;
use Tinvar\Console\Deploy\RecipeFile;
use Tinvar\Console\Deploy\RecipeParts;

/**
 * Fly.io: the app's image on a Firecracker machine, its SQLite database on
 * a volume, the secrets in `fly secrets`. Fly's edge cannot send the proxy
 * secret, so nothing is pinned unless Cloudflare stands in front and sends
 * it; then fly-proxy's range is (RecipeParts::proxies()).
 *
 * One container: a machine runs the image's entrypoint, which starts PHP
 * and the renderers as two users. Fly's health checks send no header of
 * ours, so the machine is checked by TCP, and the probes stay behind the
 * probe token for a monitor of your own.
 */
final readonly class FlyRecipe implements DeployRecipe
{
    public function target(): DeployTarget
    {
        return DeployTarget::Fly;
    }

    public function write(DeployApp $app): Recipe
    {
        RecipeParts::oneContainerOnly($app, 'Fly.io');
        $proxy = RecipeParts::proxySecret($this->target());
        $secrets = [...RecipeParts::appSecrets($this->target()), ...($app->front !== null ? [$proxy] : [])];
        $domain = $app->domain ?? "https://{$app->name}.fly.dev";
        $proxies = RecipeParts::proxies(RecipeParts::FLY, $app, 'Fly.io');

        $env = [
            ...$proxies['env'],
            'TINVAR_APP_DOMAIN' => $domain,
            'APP_URL' => $domain,
            ...RecipeParts::databaseEnvironment($app, 'CHANGE-ME.flycast'),
        ];
        if ($app->sendingMail() !== '') {
            $env['TINVAR_MAIL_PROVIDER'] = $app->sendingMail();
            foreach (RecipeParts::mailVariables($app)['env'] as $variable) {
                $env[$variable] = '';
            }
        }
        $lines = '';
        foreach ($env as $name => $value) {
            $lines .= "  {$name} = " . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
        }

        $mounts = RecipeParts::sqlite($app) ? <<<'TOML'

            # The SQLite database, and the files kept beside it, on a volume of its
            # own: one machine, since a volume is one machine's.
            [mounts]
              source = "app_data"
              destination = "/var/lib/app/data"

            TOML : '';
        $addresses = preg_replace('/^/m', '# ', rtrim($proxies['comment'])) . "\n";

        $toml = <<<TOML
            # Fly.io, written by `bin/tinvar deploy:init fly` (ADR 0157 in the
            # framework's repository). No secret is in this file: the app key and the
            # probe token are in `fly secrets`, set from files outside the project.
            app = "{$app->name}"
            primary_region = "iad"

            # FrankenPHP finishes its requests (up to 10 s), then the renderers drain
            # theirs (up to 75 s), as the image's compose file gives it 120 s.
            kill_timeout = 120

            [build]
              dockerfile = "Dockerfile"

            [env]
            {$this->comment($addresses)}{$lines}
            [http_service]
              internal_port = 8080
              force_https = true
              auto_stop_machines = "off"
              auto_start_machines = true
              min_machines_running = 1

            # By TCP: Fly.io's checks send no Authorization header, and /_health,
            # /_ready and /_metrics answer only the probe token (TINVAR_PROBE_TOKEN).
            # Point a monitor of your own at them with `Authorization: Bearer <token>`.
            [checks.alive]
              type = "tcp"
              port = 8080
              interval = "15s"
              timeout = "5s"
              grace_period = "60s"
            {$mounts}
            [[vm]]
              memory = "1gb"
              cpus = 1

            TOML;

        $steps = [
            '# Create the app from fly.toml, without deploying it yet.',
            'fly launch --no-deploy --copy-config --name ' . $app->name,
            ...(RecipeParts::sqlite($app) ? ['# The SQLite database\'s volume, in the region fly.toml names.', 'fly volumes create app_data --size 1 --region iad'] : [
                "# The database: create it (fly postgres create, or your provider's), put its host in APP_DATABASE_HOST in fly.toml, and its password in the secrets below.",
            ]),
            '# The secrets, read from their files outside the project, into fly secrets.',
            RecipeParts::lines($app, $secrets) . ' | fly secrets import',
            ...array_map(static fn (string $variable): string => "printf '{$variable}=%s\\n' \"\$(cat /path/to/{$variable})\" | fly secrets import", RecipeParts::broughtSecrets($app)),
            ...RecipeParts::mailSteps($app),
            ...RecipeParts::frontSteps($app, $proxy, $app->host("{$app->name}.fly.dev")),
            'fly deploy',
            '# Check it, through /_health with the probe token:',
            RecipeParts::probeStep($app, $secrets[1], $domain),
        ];

        return new Recipe([new RecipeFile('fly.toml', $toml)], $secrets, $steps);
    }

    private function comment(string $lines): string
    {
        // Indented as the [env] table's keys are.
        return preg_replace('/^/m', '  ', rtrim($lines)) . "\n";
    }
}
