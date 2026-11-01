<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Mail\MailConfig;
use Tinvar\Mail\MailTransports;
use Tinvar\Outbound\FakeOutboundHttp;

/**
 * Where mail goes (ADR 0147): the transport TINVAR_MAIL_PROVIDER names,
 * built as MailTransports builds it at boot -- every provider named, every
 * variable it is missing, and, where the environment says production or
 * staging, a provider that sends nothing -- and the providers' webhooks.
 * Nothing is sent: a transport is built, never asked to deliver.
 */
final readonly class MailCheck implements DoctorCheck
{
    public function title(): string
    {
        return 'Mail';
    }

    public function run(DoctorContext $context): array
    {
        // Built through a client that sends nothing, and a log path of its
        // own: building is the check, and a `log` provider with no
        // TINVAR_MAIL_LOG writes where the app's own log would.
        $transports = new MailTransports(new FakeOutboundHttp(), logPath: sys_get_temp_dir() . '/tinvar-doctor-mail.log');
        $problems = [];
        try {
            $transports->transport($context->env);
        } catch (ConfigException $e) {
            $problems = $e->problems;
        }
        try {
            $transports->webhooks($context->env);
        } catch (ConfigException $e) {
            $problems = [...$problems, ...$e->problems];
        }

        $findings = [];
        foreach (array_values(array_unique($problems)) as $problem) {
            $findings[] = Finding::problem($problem, 'Set the variable it names -- each provider reads its own, and every key may come from a file, as TINVAR_RESEND_KEY_FILE -- or name another provider in TINVAR_MAIL_PROVIDER.');
        }
        if ($findings !== []) {
            return $findings;
        }

        try {
            $providers = array_map(static fn ($p): string => $p->value, Configs::load(MailConfig::class, $context->env)->provider);
        } catch (ConfigException) {
            $providers = [];
        }

        return [Finding::passed($providers === [] ? 'No provider named: mail is written to a log, as on a developer\'s machine.' : 'Mail goes through ' . implode(', then ', $providers) . ', with every variable it needs.')];
    }
}
