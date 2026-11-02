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
 * Render: a Blueprint (render.yaml) with the app's image as a Docker web
 * service, its SQLite database on a persistent disk -- or a Render
 * Postgres database whose host, user and password Render hands the service
 * itself -- the secrets as variables Render asks for when the Blueprint is
 * applied (`sync: false`), never written here. Render's edge cannot send
 * the proxy secret, so nothing is pinned unless Cloudflare of your own
 * stands in front and sends it (RecipeParts::proxies()).
 *
 * Render's health check sends no header of ours, so none is set: Render
 * then waits for the port, and the probes stay behind the probe token.
 */
final readonly class RenderRecipe implements DeployRecipe
{
    public function target(): DeployTarget
    {
        return DeployTarget::Render;
    }

    public function write(DeployApp $app): Recipe
    {
        RecipeParts::oneContainerOnly($app, 'Render');
        $proxy = RecipeParts::proxySecret($this->target());
        $secrets = [...RecipeParts::appSecrets($this->target()), ...($app->front !== null ? [$proxy] : [])];
        $domain = $app->domain ?? "https://{$app->name}.onrender.com";
        $proxies = RecipeParts::proxies(RecipeParts::RENDER, $app, 'Render');

        $vars = [];
        foreach ($proxies['env'] as $variable => $value) {
            $vars[] = [$variable, 'value', $value];
        }
        $vars[] = ['TINVAR_APP_DOMAIN', 'value', $domain];
        foreach ($secrets as $secret) {
            $vars[] = [$secret->variable, 'sync', null];
        }
        if ($app->database->isServer()) {
            $database = "{$app->name}-db";
            $vars[] = ['APP_DATABASE_DRIVER', 'value', $app->database->value];
            foreach (['APP_DATABASE_HOST' => 'host', 'APP_DATABASE_PORT' => 'port', 'APP_DATABASE_NAME' => 'database', 'APP_DATABASE_USER' => 'user', 'APP_DATABASE_PASSWORD' => 'password'] as $variable => $property) {
                $vars[] = [$variable, 'database', "{$database}:{$property}"];
            }
        }
        if ($app->sendingMail() !== '') {
            $vars[] = ['TINVAR_MAIL_PROVIDER', 'value', $app->sendingMail()];
            foreach ([...RecipeParts::mailVariables($app)['env'], ...RecipeParts::mailVariables($app)['secrets']] as $variable) {
                $vars[] = [$variable, 'sync', null];
            }
        }

        $yaml = "    envVars:\n";
        foreach ($vars as [$key, $kind, $value]) {
            $yaml .= "      - key: {$key}\n";
            $yaml .= match ($kind) {
                'value' => '        value: ' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n",
                'sync' => "        sync: false\n",
                'database' => "        fromDatabase:\n          name: " . explode(':', (string) $value)[0] . "\n          property: " . explode(':', (string) $value)[1] . "\n",
            };
        }

        $disk = RecipeParts::sqlite($app) ? <<<'YAML'
                # The SQLite database, and the files kept beside it: one instance,
                # since a disk is one instance's.
                disk:
                  name: data
                  mountPath: /var/lib/app/data
                  sizeGB: 1

            YAML : '';
        $databases = $app->database->isServer() && $app->database->value === 'postgres' ? <<<YAML

            databases:
              - name: {$app->name}-db
                databaseName: {$this->underscored($app->name)}
                user: {$this->underscored($app->name)}
                plan: basic-256mb

            YAML : '';

        // Behind Render's load balancer (inside 10.0.0.0/8), which is the
        // peer: a front of your own names the client and the host itself.
        $comment = preg_replace('/^/m', '    # ', rtrim($proxies['comment'])) . "\n";

        $blueprint = <<<YAML
            # Render, written by `bin/tinvar deploy:init render` (ADR 0157 in the
            # framework's repository). No secret is in this file: Render asks for
            # each `sync: false` variable when the Blueprint is applied.
            services:
              - type: web
                name: {$app->name}
                runtime: docker
                dockerfilePath: ./Dockerfile
                plan: starter
                numInstances: 1
                # FrankenPHP finishes its requests (up to 10 s), then the renderers
                # drain theirs (up to 75 s).
                maxShutdownDelaySeconds: 120
                # No healthCheckPath: Render's checks send no Authorization header,
                # and /_health answers only the probe token. Render waits for the
                # port instead; point a monitor of your own at the probes.
            {$disk}{$comment}{$yaml}{$databases}
            YAML;

        $steps = [
            '# Commit render.yaml, then in the Render dashboard: New, Blueprint, and this repository.',
            '# Render asks for each sync: false variable. Paste each from its file, which never leaves this machine otherwise:',
            ...array_map(static fn ($secret): string => "#   {$secret->variable}: pbcopy < " . escapeshellarg($app->secretFile($secret)) . '   (xclip -selection clipboard on Linux)', $secrets),
            ...array_map(static fn (string $variable): string => "#   {$variable}: your provider's value", array_values(array_diff(RecipeParts::broughtSecrets($app), ['APP_DATABASE_PASSWORD']))),
            ...($app->database->isServer() && $app->database->value !== 'postgres' ? ["# The {$app->database->title()} database: Render runs none; set APP_DATABASE_HOST, NAME, USER and PASSWORD to your provider's in place of the fromDatabase entries."] : []),
            ...RecipeParts::mailSteps($app),
            ...RecipeParts::frontSteps($app, $proxy, $app->host("{$app->name}.onrender.com")),
            '# Check it, through /_health with the probe token:',
            RecipeParts::probeStep($app, $secrets[1], $domain),
        ];

        return new Recipe([new RecipeFile('render.yaml', $blueprint)], $secrets, $steps);
    }

    private function underscored(string $name): string
    {
        return str_replace('-', '_', $name);
    }
}
