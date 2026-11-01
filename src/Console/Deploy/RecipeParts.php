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
     * 172.16.0.0/12 (fly-proxy, then the machine's own eth0 range); Fly.io
     * appends the app's own public address last in X-Forwarded-For
     * ("the rightmost address will be a shared or dedicated IP address
     * assigned to your app"), so that is pinned too (DeployApp::$appAddresses).
     * Its edge sets no header of ours: no secret is possible.
     */
    public const array FLY = ['172.16.0.0/12'];

    /**
     * Render's load balancer connects from its private network, inside
     * 10.0.0.0/8, behind Cloudflare, which Render puts in front of every
     * web service: so Cloudflare's published ranges are the proxies before
     * it (cloudflare.com/ips). Neither can be told to send a header.
     */
    public const array RENDER = [
        '10.0.0.0/8',
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * Railway's edge connects from its shared address space, 100.64.0.0/10,
     * and overwrites any X-Forwarded-For a client sent. Railway does not
     * publish the range as a contract, so the docs page says how to watch
     * it. No header of ours is possible.
     */
    public const array RAILWAY = ['100.64.0.0/10'];

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

    /** The proof a VPS's Caddy sends in X-Tinvar-Proxy (ADR 0134). */
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
     * A step saying mail has no provider, where it has none that sends.
     *
     * @return list<string>
     */
    public static function mailSteps(DeployApp $app): array
    {
        return $app->sendingMail() === ''
            ? ['# Mail: no provider that sends is named. Once the app sends mail, set TINVAR_MAIL_PROVIDER (smtp, ses, postmark, mailgun, resend or sendgrid) and its variables: production refuses log and array.']
            : [];
    }

    /**
     * The trusted ranges for $platform, with the app's own addresses where its edge names them.
     *
     * @param list<string> $platform
     */
    public static function ranges(array $platform, DeployApp $app): string
    {
        $own = array_map(static fn (string $a): string => str_contains($a, '/') ? $a : $a . (str_contains($a, ':') ? '/128' : '/32'), $app->appAddresses);

        return implode(',', [...$platform, ...$own]);
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
