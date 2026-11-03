<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy;

use Tinvar\Mail\MailProvider;
use Tinvar\Orm\DatabaseDriver;

/**
 * What every recipe says the same way: the secrets, the database's and
 * mail's variables, the ranges each platform's edge connects from, and the
 * one way a secret moves from its file into a store -- read by the shell,
 * never put on a command line a process listing shows.
 */
final class RecipeParts
{
    /**
     * Fly.io's proxy connects to a machine from its private network,
     * 172.16.0.0/12 (fly-proxy, then the machine's own eth0 range). Fly's
     * edge sets no header of ours.
     */
    public const array FLY = ['172.16.0.0/12'];

    /**
     * Render's load balancer connects from its private network, inside
     * 10.0.0.0/8, behind the Cloudflare Render puts in front of every web
     * service. Neither can be told to send a header.
     */
    public const array RENDER = ['10.0.0.0/8'];

    /** What may stand in front of a platform's edge and send the proxy secret: `--front=cloudflare`. */
    public const array FRONTS = ['cloudflare'];

    /**
     * The network a VPS's container is pinned to, so its gateway -- where
     * the host's Caddy reaches the published port from -- is a known range;
     * a secret then tells Caddy apart from anything else on the host.
     */
    public const string VPS_SUBNET = '10.213.92.0/24';

    /**
     * The secrets every deployment needs: the production app key and probe token.
     *
     * @return list<PlatformSecret>
     */
    public static function appSecrets(DeployTarget $target): array
    {
        return [
            new PlatformSecret('TINVAR_APP_KEY', "{$target->value}.key", 'the app key'),
            new PlatformSecret('TINVAR_PROBE_TOKEN', "{$target->value}.probe-token", 'the probe token'),
        ];
    }

    /** The proof a proxy in front sends in X-Tinvar-Proxy (ADR 0134): a VPS's Caddy, or Cloudflare in front of a platform. */
    public static function proxySecret(DeployTarget $target): PlatformSecret
    {
        return new PlatformSecret('TINVAR_TRUSTED_PROXY_SECRET', "{$target->value}.proxy-secret", 'the trusted proxy secret');
    }

    /**
     * Shell that writes NAME=value lines for $secrets, each value read
     * from its file: `printf 'A=%s\nB=%s\n' "$(cat 'a')" "$(cat 'b')"`.
     * printf is the shell's own, so no value reaches a process listing.
     *
     * @param list<PlatformSecret> $secrets
     */
    public static function lines(DeployApp $app, array $secrets): string
    {
        $format = '';
        $values = [];
        foreach ($secrets as $secret) {
            $format .= "{$secret->variable}=%s\\n";
            $values[] = '"$(cat ' . escapeshellarg($app->secretFile($secret)) . ')"';
        }

        return "printf '{$format}' " . implode(' ', $values);
    }

    /**
     * The database's variables a server's deployment sets in the open --
     * never the password, which is the store's.
     *
     * @return array<string, string>
     */
    public static function databaseEnvironment(DeployApp $app, string $host = '', string $name = ''): array
    {
        if (!$app->database->isServer()) {
            return [];
        }

        return [
            'APP_DATABASE_DRIVER' => $app->database->value,
            'APP_DATABASE_HOST' => $host,
            'APP_DATABASE_PORT' => (string) $app->database->defaultPort(),
            'APP_DATABASE_NAME' => $name === '' ? str_replace('-', '_', $app->name) : $name,
            'APP_DATABASE_USER' => str_replace('-', '_', $app->name),
        ];
    }

    /**
     * Each mail provider's variables: what it reads in the open, and the
     * secrets the store holds.
     *
     * @return array{env: list<string>, secrets: list<string>}
     */
    public static function mailVariables(DeployApp $app): array
    {
        $env = [];
        $secrets = [];
        foreach ($app->mail as $provider) {
            [$open, $secret] = match ($provider) {
                MailProvider::Smtp => [['TINVAR_SMTP_HOST', 'TINVAR_SMTP_PORT', 'TINVAR_SMTP_USERNAME'], ['TINVAR_SMTP_PASSWORD']],
                MailProvider::Ses => [['TINVAR_SES_REGION', 'TINVAR_SES_KEY'], ['TINVAR_SES_SECRET']],
                MailProvider::Postmark => [[], ['TINVAR_POSTMARK_TOKEN']],
                MailProvider::Mailgun => [['TINVAR_MAILGUN_DOMAIN', 'TINVAR_MAILGUN_REGION'], ['TINVAR_MAILGUN_KEY']],
                MailProvider::Resend => [[], ['TINVAR_RESEND_KEY']],
                MailProvider::SendGrid => [[], ['TINVAR_SENDGRID_KEY']],
                MailProvider::Log, MailProvider::Array => [[], []],
            };
            array_push($env, ...$open);
            array_push($secrets, ...$secret);
        }

        return ['env' => $env, 'secrets' => $secrets];
    }

    /**
     * The secrets the person brings -- the database's password, the mail
     * provider's keys -- which no recipe can generate.
     *
     * @return list<string>
     */
    public static function broughtSecrets(DeployApp $app): array
    {
        return [
            ...($app->database->isServer() ? ['APP_DATABASE_PASSWORD'] : []),
            ...self::mailVariables($app)['secrets'],
        ];
    }

    /**
     * The step that checks the deployment through /_health with the probe
     * token, read from its file and handed to curl on standard input, so
     * it is on no command line.
     */
    public static function probeStep(DeployApp $app, PlatformSecret $token, string $origin): string
    {
        return "printf 'Authorization: Bearer %s\\n' \"\$(cat " . escapeshellarg($app->secretFile($token)) . ')" | curl --fail --header @- ' . $origin . '/_health';
    }

    /**
     * The mail steps: one saying mail has no provider, where it has none
     * that sends, and one naming APP_MAIL_FROM and APP_ADMINS, which only
     * the person deploying can say.
     *
     * @return list<string>
     */
    public static function mailSteps(DeployApp $app): array
    {
        return [
            ...($app->sendingMail() === ''
                ? ['# Mail: no provider that sends is named, and the app sends mail -- its accounts\' links. Set TINVAR_MAIL_PROVIDER (smtp, ses, postmark, mailgun, resend or sendgrid) and its variables: production refuses to boot with none, log or array.']
                : []),
            '# Set APP_MAIL_FROM to the address the app\'s email comes from, which production refuses to boot without, and APP_ADMINS to the accounts that may open /admin, comma-separated.',
        ];
    }

    /**
     * What a platform's config says of its proxies. With a front
     * (Cloudflare) that sends the proxy secret, the platform's edge -- the
     * peer that connects -- is pinned, and the front names the client in
     * X-Tinvar-Client and the host in X-Tinvar-Host
     * (TINVAR_TRUSTED_PROXY_CLIENT=header): its rule sends the secret for a
     * request a Cloudflare Worker -- anyone's -- sends to the domain too,
     * and the Worker writes X-Forwarded-For and X-Forwarded-Host as it
     * likes, so the client is Cloudflare's `ip.src` and the host its
     * `http.host`, which it cannot choose (ADR 0134). X-Forwarded-For is
     * then never read, so neither Cloudflare's ranges nor the app's own
     * addresses, which were pinned only to walk it, are. With none,
     * nothing is pinned:
     * a range wider than one address is refused at boot without the secret
     * (ADR 0134), and the platform's edge cannot send one, so every visitor
     * is counted as the edge's one address -- shared throttle budgets, but
     * no client able to name its own.
     *
     * @param list<string> $platform the edge's ranges
     * @return array{env: array<string, string>, comment: string}
     */
    public static function proxies(array $platform, DeployApp $app, string $name): array
    {
        if ($app->front === null) {
            return ['env' => [], 'comment' => "No TINVAR_TRUSTED_PROXIES: {$name}'s edge cannot send TINVAR_TRUSTED_PROXY_SECRET,\nand a range is refused without it (ADR 0134), so every visitor is counted as the\nedge's one address. Put Cloudflare in front (deploy:init --front=cloudflare) to\ncount each visitor apart.\n"];
        }

        return [
            'env' => [
                'TINVAR_TRUSTED_PROXIES' => implode(',', $platform),
                'TINVAR_TRUSTED_PROXY_CLIENT' => 'header',
            ],
            'comment' => "{$name}'s edge, each request proven by TINVAR_TRUSTED_PROXY_SECRET, which\nCloudflare sends in X-Tinvar-Proxy. The client is the address Cloudflare names in\nX-Tinvar-Client, its ip.src, and the host the one it names in X-Tinvar-Host, its\nhttp.host: X-Forwarded-For and X-Forwarded-Host, which a Cloudflare Worker writes\nas it likes, are not read, so no Cloudflare range is pinned.\n",
        ];
    }

    /**
     * The steps that have Cloudflare send the proxy secret, where it is in
     * front.
     *
     * @return list<string>
     */
    public static function frontSteps(DeployApp $app, PlatformSecret $proxy, string $host): array
    {
        if ($app->front === null) {
            return [];
        }

        return [
            "# Cloudflare in front: proxy {$host} through it (the orange cloud), and add a Transform Rule",
            '# (Rules, Transform Rules, Modify Request Header) for it: Set static X-Tinvar-Proxy to the value in',
            '# ' . $app->secretFile($proxy) . ' (pbcopy < ' . escapeshellarg($app->secretFile($proxy)) . '), and in the same rule',
            '# Set dynamic X-Tinvar-Client to ip.src, and Set dynamic X-Tinvar-Host to http.host. Cloudflare',
            '# overwrites any of them a client sent; a request that reaches the platform any other way has',
            '# none, and names no client and no host.',
        ];
    }

    /** Whether $app's database is SQLite: one instance, its file on a volume. */
    public static function sqlite(DeployApp $app): bool
    {
        return $app->database === DatabaseDriver::Sqlite;
    }

    /** Refuses the two-container layout, saying where it runs instead. */
    public static function oneContainerOnly(DeployApp $app, string $platform): void
    {
        if ($app->layout === ContainerLayout::Two) {
            throw new \InvalidArgumentException("{$platform} runs the one-container layout: its instances share no socket volume between two containers. Use --layout=one, or vps-compose for two containers.");
        }
    }
}
