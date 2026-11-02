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
 * A VPS with systemd: the app's image run by a unit as the compose file
 * runs it -- read-only, every capability dropped but the entrypoint's
 * three, no-new-privileges -- on a network pinned to a known subnet, and
 * the host's own Caddy in front for TLS, sending the trusted proxy secret
 * in X-Tinvar-Proxy (ADR 0134). The container's gateway, which Caddy's
 * connection to the published port arrives from, is pinned; the secret
 * tells Caddy apart from anything else on the host that reaches that port.
 *
 * The secrets are root's files on the server, /etc/<name>/app.env and
 * proxy.env, mode 0600, written over ssh from the files outside the
 * project they were generated into: never in the repository.
 */
final readonly class VpsSystemdRecipe implements DeployRecipe
{
    public function target(): DeployTarget
    {
        return DeployTarget::VpsSystemd;
    }

    public function write(DeployApp $app): Recipe
    {
        RecipeParts::oneContainerOnly($app, 'The systemd unit');
        $proxy = RecipeParts::proxySecret($this->target());
        $secrets = [...RecipeParts::appSecrets($this->target()), $proxy];
        $host = $app->host('app.example.com');
        $name = $app->name;
        $subnet = RecipeParts::VPS_SUBNET;

        $env = [
            'TINVAR_TRUSTED_PROXIES' => $subnet,
            'TINVAR_APP_DOMAIN' => "https://{$host}",
            'TINVAR_PROBE_TRUST_LOOPBACK' => '0',
            ...RecipeParts::databaseEnvironment($app, 'db.internal'),
            ...($app->sendingMail() !== '' ? ['TINVAR_MAIL_PROVIDER' => $app->sendingMail()] : []),
        ];
        $flags = '';
        foreach ($env as $variable => $value) {
            $flags .= "  --env {$variable}={$value} \\\n";
        }
        $data = RecipeParts::sqlite($app) ? "  --volume {$name}-data:/var/lib/app/data \\\n" : '';

        $unit = <<<UNIT
            # {$name} under systemd, written by `bin/tinvar deploy:init vps-systemd`
            # (ADR 0157 in the framework's repository): the app's image, run as its
            # compose file runs it, behind the host's Caddy. No secret is in this
            # file: they are in /etc/{$name}/app.env, root's alone.
            [Unit]
            Description={$name}: the Tinvar app's image, FrankenPHP and the renderers
            Requires=docker.service
            After=docker.service network-online.target

            [Service]
            # A network pinned to {$subnet}, so the gateway Caddy's connection to the
            # published port arrives from is a range TINVAR_TRUSTED_PROXIES can name.
            ExecStartPre=-/usr/bin/docker network create --subnet {$subnet} {$name}
            ExecStartPre=-/usr/bin/docker rm --force {$name}
            ExecStart=/usr/bin/docker run --rm --name {$name} --network {$name} \\
              --publish 127.0.0.1:8080:8080 \\
              --env-file /etc/{$name}/app.env \\
            {$flags}  --read-only \\
              --tmpfs /tmp:rw,nosuid,nodev,mode=1777 \\
              --tmpfs /run/tinvar-renderer:rw,nosuid,nodev,noexec,uid=10002,gid=10003,mode=0750 \\
            {$data}  --cap-drop ALL --cap-add SETUID --cap-add SETGID --cap-add SETPCAP \\
              --security-opt no-new-privileges:true \\
              {$name}:latest
            # FrankenPHP finishes its requests (up to 10 s), then the renderers drain
            # theirs (up to 75 s).
            ExecStop=/usr/bin/docker stop --time 120 {$name}
            TimeoutStopSec=130
            Restart=on-failure
            RestartSec=5

            [Install]
            WantedBy=multi-user.target

            UNIT;

        $caddyfile = <<<CADDY
            # The host's Caddy in front of {$name}, written by `bin/tinvar deploy:init
            # vps-systemd`: TLS for {$host}, and the proof the app takes it for its
            # proxy by. Caddy sets X-Forwarded-For to the client it was connected
            # from, never what a client sent, and header_up overwrites any
            # X-Tinvar-Proxy a client sent (ADR 0134).
            {$host} {
            	reverse_proxy 127.0.0.1:8080 {
            		header_up X-Tinvar-Proxy {env.TINVAR_TRUSTED_PROXY_SECRET}
            	}
            }

            CADDY;

        $dropIn = <<<CONF
            # Caddy's systemd drop-in, written by `bin/tinvar deploy:init vps-systemd`:
            # the trusted proxy secret the Caddyfile sends, from root's file.
            [Service]
            EnvironmentFile=/etc/{$name}/proxy.env

            CONF;

        $write = static fn (string $file): string => "'sudo sh -c \"umask 077 && mkdir -p /etc/{$name} && cat > /etc/{$name}/{$file}\"'";
        $steps = [
            "# On the server: Docker and Caddy installed (caddyserver.com/docs/install), this project at /srv/{$name}, and {$host} pointing at it.",
            "ssh SERVER 'cd /srv/{$name} && docker build --tag {$name}:latest .'",
            '# The secrets, from their files on this machine into root\'s on the server; nothing is printed:',
            RecipeParts::lines($app, $secrets) . " | ssh SERVER {$write('app.env')}",
            RecipeParts::lines($app, [$proxy]) . " | ssh SERVER {$write('proxy.env')}",
            ...array_map(static fn (string $variable): string => "# Add {$variable}=... (your provider's) to /etc/{$name}/app.env on the server.", [...RecipeParts::mailVariables($app)['env'], ...RecipeParts::broughtSecrets($app)]),
            ...RecipeParts::mailSteps($app),
            '# Then, on the server, from /srv/' . $name . ':',
            "sudo install -m 0644 deploy/vps-systemd/{$name}.service /etc/systemd/system/{$name}.service",
            "sudo install -D -m 0644 deploy/vps-systemd/caddy.conf /etc/systemd/system/caddy.service.d/{$name}.conf",
            'sudo install -m 0644 deploy/vps-systemd/Caddyfile /etc/caddy/Caddyfile',
            "sudo systemctl daemon-reload && sudo systemctl enable --now {$name} && sudo systemctl restart caddy",
            '# Check it, through /_health with the probe token:',
            RecipeParts::probeStep($app, $secrets[1], "https://{$host}"),
        ];

        return new Recipe([
            new RecipeFile("deploy/vps-systemd/{$name}.service", $unit),
            new RecipeFile('deploy/vps-systemd/Caddyfile', $caddyfile),
            new RecipeFile('deploy/vps-systemd/caddy.conf', $dropIn),
        ], $secrets, $steps);
    }
}
