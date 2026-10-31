<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Attributes\ListOf;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Secret;

/**
 * Amazon SES's settings (ADR 0146): TINVAR_SES_REGION, TINVAR_SES_KEY (the
 * access key id), TINVAR_SES_SECRET -- or _SECRET_FILE -- and, for a
 * temporary credential, TINVAR_SES_SESSION_TOKEN; TINVAR_SES_CONFIGURATION_SET
 * names the configuration set SES publishes the message's events from.
 *
 * Its webhook is SNS, whose signature is AWS's for every topic in every
 * account: so TINVAR_SES_WEBHOOK_TOPICS, the topic ARNs the application's
 * own SES events come from, is what makes a notification its own. Without
 * it the endpoint takes none.
 */
#[Config(prefix: 'TINVAR_SES_')]
final readonly class SesConfig
{
    /** @param list<string> $webhookTopics */
    public function __construct(
        public ?string $region = null,
        public ?string $key = null,
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $secret = null,
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $sessionToken = null,
        public ?string $configurationSet = null,
        #[ListOf('string')]
        public array $webhookTopics = [],
    ) {
        foreach ($webhookTopics as $topic) {
            if (preg_match(SesWebhook::TOPIC_ARN, $topic) !== 1) {
                throw ConfigException::invalid('TINVAR_SES_WEBHOOK_TOPICS', 'is a comma-separated list of SNS topic ARNs: arn:aws:sns:<region>:<account>:<topic>');
            }
        }
    }
}
