<?php

declare(strict_types=1);

/**
 * Mail configured from the environment: TINVAR_MAIL_PROVIDER and each
 * provider's own variables, secrets from _FILE as well, a list a failover
 * or a round robin, and every missing or malformed setting refused at boot
 * by its variable's name, never its value.
 */

namespace Tinvar\Tests\Mail\MailConfig;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\FailoverTransport;
use Tinvar\Mail\LogTransport;
use Tinvar\Mail\MailTransports;
use Tinvar\Mail\Providers\MailgunTransport;
use Tinvar\Mail\Providers\MailgunWebhook;
use Tinvar\Mail\Providers\PostmarkTransport;
use Tinvar\Mail\Providers\PostmarkWebhook;
use Tinvar\Mail\Providers\ResendTransport;
use Tinvar\Mail\Providers\ResendWebhook;
use Tinvar\Mail\Providers\SendGridTransport;
use Tinvar\Mail\Providers\SendGridWebhook;
use Tinvar\Mail\Providers\SesTransport;
use Tinvar\Mail\Providers\SesWebhook;
use Tinvar\Mail\RoundRobinTransport;
use Tinvar\Mail\SmtpTransport;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Support\PrivateDirectory;

function transports(?string $log = null): MailTransports
{
    return new MailTransports(new FakeOutboundHttp(), null, $log);
}

/** @param array<string, string> $env */
function problems(array $env, bool $webhooks = false): array
{
    try {
        $webhooks ? transports()->webhooks($env) : transports()->transport($env);
    } catch (ConfigException $e) {
        return $e->problems;
    }

    return [];
}

it('builds each provider from its own variables', function (string $provider, array $env, string $class) {
    expect(transports('/tmp/never-written.log')->transport(['TINVAR_MAIL_PROVIDER' => $provider, ...$env]))->toBeInstanceOf($class);
})->with([
    'smtp' => ['smtp', ['TINVAR_SMTP_HOST' => 'smtp.example.test', 'TINVAR_SMTP_USERNAME' => 'mailer', 'TINVAR_SMTP_PASSWORD' => 's3cret-password'], SmtpTransport::class],
    'ses' => ['ses', ['TINVAR_SES_REGION' => 'eu-west-1', 'TINVAR_SES_KEY' => 'AKIDEXAMPLE', 'TINVAR_SES_SECRET' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'], SesTransport::class],
    'postmark' => ['postmark', ['TINVAR_POSTMARK_TOKEN' => 'f00dbabe-1234-4abc-9def-0123456789ab', 'TINVAR_POSTMARK_STREAM' => 'broadcast'], PostmarkTransport::class],
    'mailgun' => ['mailgun', ['TINVAR_MAILGUN_KEY' => 'key-3ax6xnjp29jd6fds4gc373sgvjxteol0', 'TINVAR_MAILGUN_DOMAIN' => 'mg.pellwick.example', 'TINVAR_MAILGUN_REGION' => 'eu'], MailgunTransport::class],
    'resend' => ['resend', ['TINVAR_RESEND_KEY' => 're_live_4f9c2a1e8b7d6c5a3f2e1d0c'], ResendTransport::class],
    'sendgrid' => ['sendgrid', ['TINVAR_SENDGRID_KEY' => 'SG.ngeVfQFYQlKU0ufo8x5d1A.TwL2iGABf9DHoTf'], SendGridTransport::class],
    'log' => ['log', ['TINVAR_ENV' => 'local'], LogTransport::class],
    'array' => ['array', ['TINVAR_ENV' => 'testing'], ArrayTransport::class],
]);

it('reads a secret from the file its _FILE variable names', function () {
    $directory = PrivateDirectory::create('tinvar-mailcfg');
    file_put_contents("{$directory}/resend-key", "re_live_from_a_mounted_secret\n");

    try {
        $transport = transports()->transport(['TINVAR_MAIL_PROVIDER' => 'resend', 'TINVAR_RESEND_KEY_FILE' => "{$directory}/resend-key"]);
        expect($transport)->toBeInstanceOf(ResendTransport::class)
            ->and(problems(['TINVAR_MAIL_PROVIDER' => 'resend', 'TINVAR_RESEND_KEY' => 're_x_1234567890', 'TINVAR_RESEND_KEY_FILE' => "{$directory}/resend-key"]))
            ->toBe(['TINVAR_RESEND_KEY and TINVAR_RESEND_KEY_FILE are both set; set one.']);
    } finally {
        unlink("{$directory}/resend-key");
        rmdir($directory);
    }
});

it('makes a list a failover in its order, or a round robin where the strategy says', function () {
    $env = [
        'TINVAR_MAIL_PROVIDER' => 'ses,postmark',
        'TINVAR_SES_REGION' => 'eu-west-1', 'TINVAR_SES_KEY' => 'AKIDEXAMPLE', 'TINVAR_SES_SECRET' => 'secret',
        'TINVAR_POSTMARK_TOKEN' => 'f00dbabe-1234-4abc-9def-0123456789ab',
    ];

    expect(transports()->transport($env))->toBeInstanceOf(FailoverTransport::class)
        ->and(transports()->transport([...$env, 'TINVAR_MAIL_STRATEGY' => 'roundrobin']))->toBeInstanceOf(RoundRobinTransport::class);
});

it('takes the application\'s default where no provider is named', function () {
    expect(transports('/tmp/never-written.log')->transport(['TINVAR_ENV' => 'local']))->toBeInstanceOf(LogTransport::class)
        ->and(problems(['TINVAR_ENV' => 'local']))->toBe(['TINVAR_MAIL_LOG is required for the log provider, as an absolute path.']);
});

it('refuses production or staging with no provider, or one that sends nowhere', function (array $where, string $named) {
    $env = [...$where, ...($named === '' ? [] : ['TINVAR_MAIL_PROVIDER' => $named])];

    expect(problems($env))->toHaveCount(1)
        ->and(problems($env)[0])->toStartWith('TINVAR_MAIL_PROVIDER')
        ->and(problems($env)[0])->toContain(array_key_first($where));
})->with([
    'TINVAR_ENV=production, unset' => [['TINVAR_ENV' => 'production'], ''],
    'APP_ENV=production, unset' => [['APP_ENV' => 'production'], ''],
    'NODE_ENV=production, log' => [['NODE_ENV' => 'production'], 'log'],
    'TINVAR_ENV=staging, log' => [['TINVAR_ENV' => 'staging'], 'log'],
    'APP_ENV=staging, array' => [['APP_ENV' => 'staging'], 'array'],
    'TINVAR_PROFILE=production, array' => [['TINVAR_PROFILE' => 'production'], 'array'],
    'TINVAR_PROFILE=staging, unset' => [['TINVAR_PROFILE' => 'staging'], ''],
    'production, a failover ending in log' => [['TINVAR_ENV' => 'production', 'TINVAR_SMTP_HOST' => 'smtp.example.test'], 'smtp,log'],
    // ADR 0213: an environment that says nothing may be production's.
    'nothing set, unset' => [['TINVAR_ENV' => ''], ''],
    'TINVAR_ENV something else, log' => [['TINVAR_ENV' => 'prod'], 'log'],
]);

it('sends to a real provider in production, and keeps the log default everywhere else', function () {
    $smtp = ['TINVAR_MAIL_PROVIDER' => 'smtp', 'TINVAR_SMTP_HOST' => 'smtp.example.test'];

    expect(transports()->transport(['TINVAR_ENV' => 'production', ...$smtp]))->toBeInstanceOf(SmtpTransport::class)
        ->and(transports()->transport(['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging', ...$smtp]))->toBeInstanceOf(SmtpTransport::class)
        ->and(transports('/tmp/never-written.log')->transport(['TINVAR_ENV' => 'local']))->toBeInstanceOf(LogTransport::class)
        ->and(transports('/tmp/never-written.log')->transport(['TINVAR_ENV' => 'testing', 'TINVAR_PROFILE' => 'test']))->toBeInstanceOf(LogTransport::class)
        ->and(transports()->transport(['TINVAR_ENV' => 'development', 'TINVAR_MAIL_PROVIDER' => 'array']))->toBeInstanceOf(ArrayTransport::class);
});

it('refuses an unknown provider, one named twice, or an unknown strategy', function () {
    expect(problems(['TINVAR_MAIL_PROVIDER' => 'brevo']))->toHaveCount(1)
        ->and(problems(['TINVAR_MAIL_PROVIDER' => 'brevo'])[0])->toContain('TINVAR_MAIL_PROVIDER')
        ->and(problems(['TINVAR_MAIL_PROVIDER' => 'array,array']))->toBe(['TINVAR_MAIL_PROVIDER names a provider twice.'])
        ->and(problems(['TINVAR_MAIL_PROVIDER' => 'array', 'TINVAR_MAIL_STRATEGY' => 'random'])[0])->toContain('TINVAR_MAIL_STRATEGY');
});

it('names every setting a provider is missing, for every provider named, in one refusal', function () {
    expect(problems(['TINVAR_MAIL_PROVIDER' => 'ses,mailgun,smtp']))->toBe([
        'TINVAR_SES_REGION is required by TINVAR_MAIL_PROVIDER, and is not set.',
        'TINVAR_SES_KEY is required by TINVAR_MAIL_PROVIDER, and is not set.',
        'TINVAR_SES_SECRET (or TINVAR_SES_SECRET_FILE) is required by TINVAR_MAIL_PROVIDER, and is not set.',
        'TINVAR_MAILGUN_KEY (or TINVAR_MAILGUN_KEY_FILE) is required by TINVAR_MAIL_PROVIDER, and is not set.',
        'TINVAR_MAILGUN_DOMAIN is required by TINVAR_MAIL_PROVIDER, and is not set.',
        'TINVAR_SMTP_HOST is required by TINVAR_MAIL_PROVIDER, and is not set.',
    ]);
});

it('refuses a malformed setting by its variable, and never quotes a secret', function () {
    $secret = 'p4ss word with a space';
    $all = [
        ...problems(['TINVAR_MAIL_PROVIDER' => 'resend', 'TINVAR_RESEND_KEY' => $secret]),
        ...problems(['TINVAR_MAIL_PROVIDER' => 'ses', 'TINVAR_SES_REGION' => 'mars-1', 'TINVAR_SES_KEY' => 'AKID', 'TINVAR_SES_SECRET' => $secret]),
        ...problems(['TINVAR_MAIL_PROVIDER' => 'mailgun', 'TINVAR_MAILGUN_KEY' => 'key-1234567890', 'TINVAR_MAILGUN_DOMAIN' => 'mg.example.test', 'TINVAR_MAILGUN_REGION' => 'ap']),
        ...problems(['TINVAR_MAIL_PROVIDER' => 'smtp', 'TINVAR_SMTP_HOST' => 'smtp.example.test', 'TINVAR_SMTP_ENCRYPTION' => 'ssl']),
        ...problems(['TINVAR_MAIL_PROVIDER' => 'smtp', 'TINVAR_SMTP_HOST' => 'smtp.example.test', 'TINVAR_SMTP_USERNAME' => 'mailer']),
    ];

    expect($all)->toHaveCount(5)
        ->and($all[0])->toStartWith('TINVAR_RESEND_* cannot make the Resend transport')
        ->and($all[1])->toStartWith('TINVAR_SES_* cannot make the Amazon SES transport')
        ->and($all[2])->toBe('TINVAR_MAILGUN_REGION is us or eu.')
        ->and($all[3])->toBe('TINVAR_SMTP_ENCRYPTION is starttls, tls or none.')
        ->and($all[4])->toContain('TINVAR_SMTP_PASSWORD')
        ->and(implode("\n", $all))->not->toContain($secret);
});

it('builds the webhooks whose settings are set, whichever provider sends', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    \assert($key !== false);
    $public = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', (string) openssl_pkey_get_details($key)['key']);

    $webhooks = transports()->webhooks([
        'TINVAR_RESEND_WEBHOOK_SECRET' => 'whsec_' . base64_encode('resend-webhook-secret-32-bytes!!'),
        'TINVAR_POSTMARK_WEBHOOK_USERNAME' => 'postmark',
        'TINVAR_POSTMARK_WEBHOOK_PASSWORD' => 'p0stm4rk-webhook-password-0123',
        'TINVAR_SES_WEBHOOK_TOPICS' => 'arn:aws:sns:eu-west-1:123456789012:ses-bounces,arn:aws:sns:eu-west-1:123456789012:ses-complaints',
        'TINVAR_MAILGUN_WEBHOOK_SECRET' => 'mailgun-http-webhook-signing-key',
        'TINVAR_SENDGRID_WEBHOOK_PUBLIC_KEY' => $public,
    ]);

    expect(array_map(static fn (object $w): string => $w::class, $webhooks))->toBe([
        ResendWebhook::class, PostmarkWebhook::class, SesWebhook::class, MailgunWebhook::class, SendGridWebhook::class,
    ])->and(transports()->webhooks([]))->toBe([]);
});

it('refuses a webhook setting that is not one, by its variable', function () {
    expect(problems(['TINVAR_RESEND_WEBHOOK_SECRET' => 'not-a-whsec!'], true))->toBe(['TINVAR_RESEND_WEBHOOK_SECRET is the whsec_... secret Resend shows for the webhook.'])
        ->and(problems(['TINVAR_SES_WEBHOOK_TOPICS' => 'arn:aws:s3:::bucket'], true)[0])->toStartWith('TINVAR_SES_WEBHOOK_TOPICS is a comma-separated list of SNS topic ARNs')
        ->and(problems(['TINVAR_POSTMARK_WEBHOOK_USERNAME' => 'postmark', 'TINVAR_POSTMARK_WEBHOOK_PASSWORD' => 'short'], true)[0])->toStartWith('TINVAR_POSTMARK_WEBHOOK_PASSWORD must be 24 characters or more')
        ->and(problems(['TINVAR_POSTMARK_WEBHOOK_USERNAME' => 'postmark'], true)[0])->toContain('set together')
        ->and(problems(['TINVAR_SENDGRID_WEBHOOK_PUBLIC_KEY' => 'bm90IGEga2V5'], true)[0])->toStartWith('TINVAR_SENDGRID_WEBHOOK_PUBLIC_KEY is the base64')
        ->and(problems(['TINVAR_MAILGUN_WEBHOOK_SECRET' => 'x'], true))->toBe(['TINVAR_MAILGUN_WEBHOOK_SECRET must be 32 characters or more: the HTTP webhook signing key Mailgun shows.']);
});

it('declares every class by the rules config classes are held to, each variable read once', function () {
    expect(Configs::check(MailTransports::configs()))->toBe([]);
});
