<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Aws\AwsCredentials;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Mail\Providers\MailgunConfig;
use Tinvar\Mail\Providers\MailgunTransport;
use Tinvar\Mail\Providers\MailgunWebhook;
use Tinvar\Mail\Providers\PostmarkConfig;
use Tinvar\Mail\Providers\PostmarkTransport;
use Tinvar\Mail\Providers\PostmarkWebhook;
use Tinvar\Mail\Providers\ResendConfig;
use Tinvar\Mail\Providers\ResendTransport;
use Tinvar\Mail\Providers\ResendWebhook;
use Tinvar\Mail\Providers\SendGridConfig;
use Tinvar\Mail\Providers\SendGridTransport;
use Tinvar\Mail\Providers\SendGridWebhook;
use Tinvar\Mail\Providers\SesConfig;
use Tinvar\Mail\Providers\SesTransport;
use Tinvar\Mail\Providers\SesWebhook;
use Tinvar\Outbound\OutboundHttp;

/**
 * The transport -- and the webhooks -- the environment names (ADR 0147):
 * TINVAR_MAIL_PROVIDER and each named provider's own variables (MailConfig).
 *
 *     $transport = new MailTransports($outbound, DkimConfig::load()->signer(), $logPath)->transport();
 *
 * Every provider named is built, and everything missing or malformed in
 * any of them is said in one ConfigException at boot, naming variables and
 * never values: a provider named without its key stops the boot rather
 * than failing every send. Two providers or more are a FailoverTransport,
 * or a RoundRobinTransport where TINVAR_MAIL_STRATEGY says so.
 *
 * The API providers send through $http, the application's one guarded
 * OutboundHttp. The DkimSigner signs what SMTP, SES and Mailgun send as
 * MIME; Resend, Postmark and SendGrid take no MIME of ours, and sign with
 * the domain each has verified.
 *
 * The explicit constructors -- new SesTransport(...), new ResendTransport(...)
 * -- are there for an application that wires mail in code instead.
 */
final readonly class MailTransports
{
    /**
     * @param string|null $logPath where the `log` provider writes, when TINVAR_MAIL_LOG does not say
     */
    public function __construct(
        private OutboundHttp $http,
        private ?DkimSigner $dkim = null,
        private ?string $logPath = null,
    ) {}

    /**
     * The transport TINVAR_MAIL_PROVIDER names, or $default's where it names none.
     *
     * @param array<string, string>|null $env a test's environment; the process's when null
     * @throws ConfigException
     */
    public function transport(?array $env = null, MailProvider $default = MailProvider::Log): Transport
    {
        $config = Configs::load(MailConfig::class, $env);
        $providers = $config->provider === [] ? [$default] : $config->provider;

        $transports = [];
        $problems = [];
        foreach ($providers as $provider) {
            try {
                $transports[] = $this->build($provider, $config, $env);
            } catch (ConfigException $e) {
                array_push($problems, ...$e->problems);
            }
        }
        if ($problems !== []) {
            throw new ConfigException($problems);
        }

        if (\count($transports) === 1) {
            return $transports[0];
        }

        return $config->strategy === MailStrategy::RoundRobin ? new RoundRobinTransport($transports) : new FailoverTransport($transports);
    }

    /**
     * The webhooks whose settings are set, for MailEventsEndpoint: each
     * provider's, whether or not it is the one sending now -- a failover's
     * second still reports what it sent.
     *
     * @param array<string, string>|null $env
     * @return list<MailWebhook>
     * @throws ConfigException
     */
    public function webhooks(?array $env = null): array
    {
        $webhooks = [];
        $problems = [];

        $resend = self::load(ResendConfig::class, $env, $problems);
        if ($resend instanceof ResendConfig && $resend->webhookSecret !== null) {
            $webhooks[] = new ResendWebhook($resend->webhookSecret);
        }
        $postmark = self::load(PostmarkConfig::class, $env, $problems);
        if ($postmark instanceof PostmarkConfig && $postmark->webhookUsername !== null && $postmark->webhookPassword !== null) {
            $webhooks[] = new PostmarkWebhook($postmark->webhookUsername, $postmark->webhookPassword);
        }
        $ses = self::load(SesConfig::class, $env, $problems);
        if ($ses instanceof SesConfig && $ses->webhookTopics !== []) {
            $webhooks[] = new SesWebhook($ses->webhookTopics, $this->http);
        }
        $mailgun = self::load(MailgunConfig::class, $env, $problems);
        if ($mailgun instanceof MailgunConfig && $mailgun->webhookSecret !== null) {
            $webhooks[] = new MailgunWebhook($mailgun->webhookSecret);
        }
        $sendGrid = self::load(SendGridConfig::class, $env, $problems);
        if ($sendGrid instanceof SendGridConfig && $sendGrid->webhookPublicKey !== null) {
            $webhooks[] = new SendGridWebhook($sendGrid->webhookPublicKey);
        }

        if ($problems !== []) {
            throw new ConfigException($problems);
        }

        return $webhooks;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, string>|null $env
     * @param list<string> $problems
     * @return T|null
     */
    private static function load(string $class, ?array $env, array &$problems): ?object
    {
        try {
            return Configs::load($class, $env);
        } catch (ConfigException $e) {
            array_push($problems, ...$e->problems);

            return null;
        }
    }

    /**
     * Every config class the transports and webhooks read, for a Kernel's
     * boot and `tinvar config` to load and list.
     *
     * @return list<class-string>
     */
    public static function configs(): array
    {
        return [MailConfig::class, SmtpConfig::class, SesConfig::class, PostmarkConfig::class, MailgunConfig::class, ResendConfig::class, SendGridConfig::class];
    }

    /**
     * @param array<string, string>|null $env
     * @throws ConfigException
     */
    private function build(MailProvider $provider, MailConfig $mail, ?array $env): Transport
    {
        return match ($provider) {
            MailProvider::Smtp => $this->smtp(Configs::load(SmtpConfig::class, $env)),
            MailProvider::Ses => $this->ses(Configs::load(SesConfig::class, $env)),
            MailProvider::Postmark => $this->postmark(Configs::load(PostmarkConfig::class, $env)),
            MailProvider::Mailgun => $this->mailgun(Configs::load(MailgunConfig::class, $env)),
            MailProvider::Resend => $this->resend(Configs::load(ResendConfig::class, $env)),
            MailProvider::SendGrid => $this->sendGrid(Configs::load(SendGridConfig::class, $env)),
            MailProvider::Log => new LogTransport($mail->log ?? $this->logPath ?? throw ConfigException::invalid('TINVAR_MAIL_LOG', 'is required for the log provider, as an absolute path')),
            MailProvider::Array => new ArrayTransport(),
        };
    }

    private function smtp(SmtpConfig $c): Transport
    {
        self::require(['TINVAR_SMTP_HOST' => $c->host]);

        return self::made('TINVAR_SMTP_*', 'SMTP', fn (): Transport => new SmtpTransport(
            (string) $c->host,
            $c->port,
            $c->username,
            $c->password?->reveal(),
            $c->encryption,
            localDomain: $c->localDomain,
            dkim: $this->dkim,
        ));
    }

    private function ses(SesConfig $c): Transport
    {
        self::require(['TINVAR_SES_REGION' => $c->region, 'TINVAR_SES_KEY' => $c->key, 'TINVAR_SES_SECRET (or TINVAR_SES_SECRET_FILE)' => $c->secret]);

        return self::made('TINVAR_SES_*', 'Amazon SES', fn (): Transport => new SesTransport(
            new AwsCredentials((string) $c->key, (string) $c->secret?->reveal(), $c->sessionToken?->reveal()),
            (string) $c->region,
            $this->http,
            $c->configurationSet,
            $this->dkim,
        ));
    }

    private function postmark(PostmarkConfig $c): Transport
    {
        self::require(['TINVAR_POSTMARK_TOKEN (or TINVAR_POSTMARK_TOKEN_FILE)' => $c->token]);

        return self::made('TINVAR_POSTMARK_*', 'Postmark', fn (): Transport => new PostmarkTransport($c->token ?? throw new \LogicException(), $this->http, $c->stream));
    }

    private function mailgun(MailgunConfig $c): Transport
    {
        self::require(['TINVAR_MAILGUN_KEY (or TINVAR_MAILGUN_KEY_FILE)' => $c->key, 'TINVAR_MAILGUN_DOMAIN' => $c->domain]);

        return self::made('TINVAR_MAILGUN_*', 'Mailgun', fn (): Transport => new MailgunTransport($c->key ?? throw new \LogicException(), (string) $c->domain, $this->http, $c->region, $this->dkim));
    }

    private function resend(ResendConfig $c): Transport
    {
        self::require(['TINVAR_RESEND_KEY (or TINVAR_RESEND_KEY_FILE)' => $c->key]);

        return self::made('TINVAR_RESEND_*', 'Resend', fn (): Transport => new ResendTransport($c->key ?? throw new \LogicException(), $this->http));
    }

    private function sendGrid(SendGridConfig $c): Transport
    {
        self::require(['TINVAR_SENDGRID_KEY (or TINVAR_SENDGRID_KEY_FILE)' => $c->key]);

        return self::made('TINVAR_SENDGRID_*', 'SendGrid', fn (): Transport => new SendGridTransport($c->key ?? throw new \LogicException(), $this->http, $c->region));
    }

    /**
     * Refuses a provider missing a setting it needs, naming each.
     *
     * @param array<string, mixed> $settings variable => value, null when unset
     * @throws ConfigException
     */
    private static function require(array $settings): void
    {
        $missing = array_keys(array_filter($settings, static fn (mixed $value): bool => $value === null));
        if ($missing !== []) {
            throw new ConfigException(array_map(static fn (string $variable): string => "{$variable} is required by TINVAR_MAIL_PROVIDER, and is not set.", $missing));
        }
    }

    /**
     * The transport $make builds, or the configuration refused: a transport
     * says what is wrong with a value without quoting a secret, so its
     * message is kept.
     *
     * @param \Closure(): Transport $make
     * @throws ConfigException
     */
    private static function made(string $variables, string $label, \Closure $make): Transport
    {
        try {
            return $make();
        } catch (\InvalidArgumentException $e) {
            throw new ConfigException(["{$variables} cannot make the {$label} transport: {$e->getMessage()}"]);
        }
    }
}
