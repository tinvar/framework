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
 * Railway: railway.json builds the app's image from its Dockerfile and runs
 * one replica; the variables are set with `railway variables` -- the ones
 * in the open from the command line, the secrets pasted into the service's
 * variables from their files, never onto a command line -- its SQLite
 * database on a volume, and Railway's edge pinned as the proxy
 * (RecipeParts::RAILWAY).
 *
 * Railway's healthcheck sends no header of ours, so none is set, and the
 * probes stay behind the probe token.
 */
final readonly class RailwayRecipe implements DeployRecipe
{
    public function target(): DeployTarget
    {
        return DeployTarget::Railway;
    }

    public function write(DeployApp $app): Recipe
    {
        RecipeParts::oneContainerOnly($app, 'Railway');
        $secrets = RecipeParts::appSecrets($this->target());

        $config = json_encode([
            '$schema' => 'https://railway.com/railway.schema.json',
            'build' => ['builder' => 'DOCKERFILE', 'dockerfilePath' => 'Dockerfile'],
            'deploy' => [
                // One replica: SQLite's volume is one replica's.
                'numReplicas' => 1,
                'restartPolicyType' => 'ON_FAILURE',
                'restartPolicyMaxRetries' => 10,
                // FrankenPHP finishes its requests, then the renderers drain theirs.
                'drainingSeconds' => 120,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $open = [
            'TINVAR_TRUSTED_PROXIES' => RecipeParts::ranges(RecipeParts::RAILWAY, $app),
            ...($app->domain !== null ? ['TINVAR_APP_DOMAIN' => $app->domain] : []),
        ];
        if ($app->database->isServer()) {
            $service = $app->database->value === 'postgres' ? 'Postgres' : 'MySQL';
            $prefix = $app->database->value === 'postgres' ? 'PG' : 'MYSQL';
            $open += [
                'APP_DATABASE_DRIVER' => $app->database->value,
                'APP_DATABASE_HOST' => "\${{{$service}.{$prefix}HOST}}",
                'APP_DATABASE_PORT' => "\${{{$service}.{$prefix}PORT}}",
                'APP_DATABASE_NAME' => $app->database->value === 'postgres' ? '${{Postgres.PGDATABASE}}' : '${{MySQL.MYSQLDATABASE}}',
                'APP_DATABASE_USER' => "\${{{$service}.{$prefix}USER}}",
                'APP_DATABASE_PASSWORD' => "\${{{$service}.{$prefix}PASSWORD}}",
            ];
        }
        if ($app->sendingMail() !== '') {
            $open['TINVAR_MAIL_PROVIDER'] = $app->sendingMail();
        }
        $set = 'railway variables';
        foreach ($open as $name => $value) {
            $set .= ' --set ' . escapeshellarg("{$name}={$value}");
        }

        $steps = [
            '# Create the project and link this directory to it.',
            'railway init --name ' . $app->name,
            ...(RecipeParts::sqlite($app)
                ? ['# The SQLite database, and the files kept beside it, on a volume.', 'railway volume add --mount-path /var/lib/app/data']
                : ['# The database, in the same project: its variables are referenced below.', 'railway add --database ' . ($app->database->value === 'postgres' ? 'postgres' : 'mysql')]),
            '# The variables in the open: Railway\'s edge, inside 100.64.0.0/10, as the proxy (it sends no header of ours, so no TINVAR_TRUSTED_PROXY_SECRET).',
            $set,
            '# The rest: paste each into the service\'s Variables -- a secret from its file, which never reaches a command line:',
            ...array_map(static fn ($secret): string => "#   {$secret->variable}: pbcopy < " . escapeshellarg($app->secretFile($secret)) . '   (xclip -selection clipboard on Linux)', $secrets),
            ...array_map(static fn (string $variable): string => "#   {$variable}: your provider's value", [...RecipeParts::mailVariables($app)['env'], ...array_values(array_diff(RecipeParts::broughtSecrets($app), ['APP_DATABASE_PASSWORD']))]),
            ...RecipeParts::mailSteps($app),
            'railway up',
            ...($app->domain === null
                ? ['# Then `railway domain`, and set TINVAR_APP_DOMAIN to the https address it gives.']
                : ['# Check it, through /_health with the probe token:', RecipeParts::probeStep($app, $secrets[1], $app->domain)]),
        ];

        return new Recipe([new RecipeFile('railway.json', $config)], $secrets, $steps);
    }
}
