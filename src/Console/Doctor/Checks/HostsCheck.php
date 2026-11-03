<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Console\AppConsole;
use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Discovery\DiscoveryFailed;

/**
 * Domains and hosts (ADR 0149): every #[Domain] a route or endpoint
 * declares served by the hosts the application configures, and every
 * entry point on a `{tenant}` host -- its own or its panel's -- reading
 * its tenant from that host, as `tinvar check` holds them
 * (AppConsole::hostViolations()); and -- what check cannot
 * see, since it reads no environment -- a server with no
 * TINVAR_APP_DOMAIN, which then builds every link and origin from
 * whatever Host a request names.
 */
final readonly class HostsCheck implements DoctorCheck
{
    public function title(): string
    {
        return 'Domains and hosts';
    }

    public function run(DoctorContext $context): array
    {
        try {
            $config = Configs::load(FrameworkConfig::class, $context->env);
            $domains = $config->domains();
        } catch (ConfigException|\InvalidArgumentException) {
            return [Finding::skipped('The framework\'s config is refused; see Config above.')];
        }
        if ($context->domains !== null) {
            $domains = ($context->domains)() ?? $domains;
        }

        $findings = [];
        try {
            $classes = $context->classes();
        } catch (DiscoveryFailed) {
            $classes = null;
            $findings[] = Finding::skipped('Discovery refused the app\'s classes; see Config above.');
        }
        if ($classes !== null) {
            $violations = AppConsole::hostViolations(
                $classes->routes,
                $classes->endpoints,
                $classes->models,
                $domains,
                $classes->actions,
                $classes->queries,
                $classes->channels,
                $classes->transfers,
                $context->panels === null ? null : ($context->panels)(),
            );
            foreach ($violations as $violation) {
                $findings[] = Finding::problem((string) $violation, 'Set TINVAR_APP_DOMAIN (and TINVAR_DOMAINS for a further domain) to serve the host it names, or take the #[Domain] off.');
            }
        }

        $production = $context->production();
        if ($domains === null && $production !== null) {
            $findings[] = Finding::warning(
                "TINVAR_APP_DOMAIN is not set, and {$production}: the app answers on any host it is sent, and builds its links from it.",
                'Set TINVAR_APP_DOMAIN to the public address, `https://app.example.com`; and TINVAR_HOST_ALIASES to any other name it answers to.',
            );
        }
        if ($findings === []) {
            $findings[] = Finding::passed($domains === null ? 'No host configured: the app routes on none, as on a developer\'s machine.' : 'Every host a route declares is one the app serves.');
        }

        return $findings;
    }
}
