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
 * variables from their files, never onto a command line -- and its SQLite
 * database on a volume. Railway's edge cannot send the proxy secret, so no
 * proxy is pinned, and every visitor is the edge's one address. A front
 * that names the client and the host itself would not need Railway's
 * X-Forwarded-For, which its edge replaces, but Railway documents no range
 * its edge connects to the app from: there is nothing to pin but every
 * address, which would leave the secret alone to tell the proxy from any
 * other peer -- the node, a hairpin, a neighbour -- and ADR 0134 holds a
 * range in front of it. So --front is refused here.
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
        if ($app->front !== null) {
            throw new \InvalidArgumentException("Railway documents no address range its edge connects to the app from, so there is no range to pin behind Cloudflare -- only every address, with the secret alone to prove the proxy: --front is for Fly.io and Render. Railway runs with no proxy pinned.");
        }
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
            ...($app->domain !== null ? ['TINVAR_APP_DOMAIN' => $app->domain, 'APP_URL' => $app->domain] : []),
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
            '# The variables in the open. No TINVAR_TRUSTED_PROXIES: Railway\'s edge cannot send TINVAR_TRUSTED_PROXY_SECRET, and a range is refused without it (ADR 0134), so every visitor is counted as the edge\'s one address.',
            $set,
            '# The rest: paste each into the service\'s Variables -- a secret from its file, which never reaches a command line:',
            ...array_map(static fn ($secret): string => "#   {$secret->variable}: pbcopy < " . escapeshellarg($app->secretFile($secret)) . '   (xclip -selection clipboard on Linux)', $secrets),
            ...array_map(static fn (string $variable): string => "#   {$variable}: your provider's value", [...RecipeParts::mailVariables($app)['env'], ...array_values(array_diff(RecipeParts::broughtSecrets($app), ['APP_DATABASE_PASSWORD']))]),
            ...RecipeParts::mailSteps($app),
            'railway up',
            ...($app->domain === null
                ? ['# Then `railway domain`, and set TINVAR_APP_DOMAIN and APP_URL to the https address it gives: production refuses to boot without APP_URL.']
                : ['# Check it, through /_health with the probe token:', RecipeParts::probeStep($app, $secrets[1], $app->domain)]),
        ];

        return new Recipe([new RecipeFile('railway.json', $config)], $secrets, $steps);
    }
}
