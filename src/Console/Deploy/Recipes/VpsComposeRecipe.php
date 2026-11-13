<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy\Recipes;

use Tinvar\Console\Deploy\ContainerLayout;
use Tinvar\Console\Deploy\DeployApp;
use Tinvar\Console\Deploy\DeployRecipe;
use Tinvar\Console\Deploy\DeployTarget;
use Tinvar\Console\Deploy\Recipe;
use Tinvar\Console\Deploy\RecipeFile;
use Tinvar\Console\Deploy\RecipeParts;

/**
 * A VPS with Docker Compose: Caddy as a service for TLS, beside the app
 * on a network pinned to a known subnet, sending the trusted proxy secret
 * in X-Tinvar-Proxy (ADR 0134) -- the app as one container (ADR 0059), or
 * as two, PHP and the renderers sharing only a socket volume, each started
 * as its own user (ADR 0071), where the Dockerfile has the `pod-php` and
 * `pod-renderer` targets.
 *
 * The secrets are root's files on the server, /etc/<name>/app.env and
 * proxy.env, mode 0600, read by compose run as root: never in the
 * repository, never in this file.
 */
final readonly class VpsComposeRecipe implements DeployRecipe
{
    public function target(): DeployTarget
    {
        return DeployTarget::VpsCompose;
    }

    public function write(DeployApp $app): Recipe
    {
        if ($app->layout === ContainerLayout::Two && !$app->podTargets) {
            throw new \InvalidArgumentException("Two containers need the Dockerfile's pod-php and pod-renderer targets, which this app's has not: the skeleton's image is one container. Use --layout=one, or add the targets as the example shop's Dockerfile has them.");
        }
        $proxy = RecipeParts::proxySecret($this->target());
        $secrets = [...RecipeParts::appSecrets($this->target()), $proxy];
        $host = $app->host('app.example.com');
        $name = $app->name;
        $subnet = RecipeParts::VPS_SUBNET;
        $two = $app->layout === ContainerLayout::Two;

        $environment = '';
        foreach ([
            'TINVAR_TRUSTED_PROXIES' => $subnet,
            'TINVAR_APP_DOMAIN' => "https://{$host}",
            'APP_URL' => "https://{$host}",
            'TINVAR_PROBE_TRUST_LOOPBACK' => '0',
            ...RecipeParts::databaseEnvironment($app, 'db.internal'),
            ...($app->sendingMail() !== '' ? ['TINVAR_MAIL_PROVIDER' => $app->sendingMail()] : []),
        ] as $variable => $value) {
            $environment .= "      {$variable}: " . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
        }
        $data = RecipeParts::sqlite($app) ? "      - data:/var/lib/app/data\n" : '';

        $app_service = $two ? <<<YAML
              # The renderers, from an image of their own, with no network at all:
              # they answer on their sockets alone (ADR 0071).
              renderers:
                build:
                  context: ../..
                  target: pod-renderer
                image: {$name}-renderer:latest
                network_mode: none
                volumes:
                  - sockets:/run/tinvar-renderer
                stop_grace_period: 80s
                <<: *locked

              app:
                build:
                  context: ../..
                  target: pod-php
                image: {$name}-php:latest
                # The socket group, whose sockets the renderers share with it alone.
                group_add: ["10003"]
                env_file: /etc/{$name}/app.env
                environment:
            {$environment}    tmpfs:
                  - /tmp:rw,nosuid,nodev,mode=1777
                volumes:
                  - sockets:/run/tinvar-renderer:ro
            {$data}    depends_on:
                  renderers:
                    condition: service_healthy
                stop_grace_period: 120s
                <<: *locked

            YAML : <<<YAML
              # PHP and the renderers in one container, each as its own user (ADR 0059).
              app:
                build:
                  context: ../..
                image: {$name}:latest
                env_file: /etc/{$name}/app.env
                environment:
            {$environment}    tmpfs:
                  - /tmp:rw,nosuid,nodev,mode=1777
                  - /run/tinvar-renderer:rw,nosuid,nodev,noexec,uid=10002,gid=10003,mode=0750
            {$this->volumes($data)}    # The entrypoint, root until both sides are started, needs only these.
                cap_add: [SETUID, SETGID, SETPCAP]
                # FrankenPHP finishes its requests (up to 10 s), then the renderers
                # drain theirs (up to 75 s).
                stop_grace_period: 120s
                <<: *locked

            YAML;
        $volumes = ($two ? "  sockets:\n" : '') . (RecipeParts::sqlite($app) ? "  data:\n" : '') . "  caddy-data:\n";

        $compose = <<<YAML
            # {$name} on a VPS, written by `php tinvar deploy:init vps-compose` (ADR
            # 0157 in the framework's repository). From the project's root, as root,
            # since the secrets are root's files:
            #
            #   sudo docker compose -f deploy/vps-compose/compose.yaml up -d --build
            #
            # No secret is in this file: they are in /etc/{$name}/app.env and
            # proxy.env, mode 0600, written by the steps deploy:init printed.
            name: {$name}

            x-locked: &locked
              read_only: true
              cap_drop: [ALL]
              security_opt:
                - no-new-privileges:true
              restart: unless-stopped

            services:
              # TLS for {$host}, and the proof the app takes it for its proxy by.
              caddy:
                # Caddy 2 by its tag, not a digest: it faces the internet, so
                # `docker compose pull` should take each security release as
                # Caddy publishes it. `tinvar deps:check` does not move it --
                # it holds the framework's own pins -- so pull it on a schedule.
                image: caddy:2
                env_file: /etc/{$name}/proxy.env
                ports:
                  - "80:80"
                  - "443:443"
                  - "443:443/udp"
                volumes:
                  - ./Caddyfile:/etc/caddy/Caddyfile:ro
                  - caddy-data:/data
                tmpfs:
                  - /config
                cap_add: [NET_BIND_SERVICE]
                depends_on: [app]
                <<: *locked

            {$app_service}
            # Pinned, so TINVAR_TRUSTED_PROXIES can name the range Caddy connects
            # from; the secret tells Caddy apart from anything else in it.
            networks:
              default:
                ipam:
                  config:
                    - subnet: {$subnet}

            volumes:
            {$volumes}
            YAML;

        $caddyfile = <<<CADDY
            # Caddy in front of {$name}, written by `php tinvar deploy:init vps-compose`:
            # TLS for {$host}, and the proof the app takes it for its proxy by. Caddy
            # sets X-Forwarded-For to the client it was connected from, never what a
            # client sent, and header_up overwrites any X-Tinvar-Proxy a client sent
            # (ADR 0134).
            #
            # A request is read within deadlines, and no body over the largest
            # upload the app takes is passed on; a body of up to a megabyte --
            # every action, query and webhook -- is buffered whole first, so a
            # client sending it slowly holds this proxy, never a worker of the
            # app's (ADR 0207).
            {
            	servers {
            		timeouts {
            			read_header 10s
            			read_body 60s
            		}
            	}
            }

            {$host} {
            	request_body {
            		max_size 11MiB
            	}
            	reverse_proxy app:8080 {
            		request_buffers 1MiB
            		header_up X-Tinvar-Proxy {env.TINVAR_TRUSTED_PROXY_SECRET}
            	}
            }

            CADDY;

        $write = static fn (string $file): string => "'sudo sh -c \"umask 077 && mkdir -p /etc/{$name} && cat > /etc/{$name}/{$file}\"'";
        $steps = [
            "# On the server: Docker with compose, this project at /srv/{$name}, and {$host} pointing at it.",
            '# The secrets, from their files on this machine into root\'s on the server; nothing is printed:',
            RecipeParts::lines($app, $secrets) . " | ssh SERVER {$write('app.env')}",
            RecipeParts::lines($app, [$proxy]) . " | ssh SERVER {$write('proxy.env')}",
            ...array_map(static fn (string $variable): string => "# Add {$variable}=... (your provider's) to /etc/{$name}/app.env on the server.", [...RecipeParts::mailVariables($app)['env'], ...RecipeParts::broughtSecrets($app)]),
            ...RecipeParts::mailSteps($app),
            "ssh SERVER 'cd /srv/{$name} && sudo docker compose -f deploy/vps-compose/compose.yaml up -d --build'",
            '# Check it, through /_health with the probe token:',
            RecipeParts::probeStep($app, $secrets[1], "https://{$host}"),
        ];

        return new Recipe([
            new RecipeFile('deploy/vps-compose/compose.yaml', $compose),
            new RecipeFile('deploy/vps-compose/Caddyfile', $caddyfile),
        ], $secrets, $steps);
    }

    private function volumes(string $data): string
    {
        return $data === '' ? '' : "    volumes:\n{$data}";
    }
}
