<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Discovery\DiscoveryFailed;

/**
 * Every #[Config] class the application registers, loaded as its boot
 * loads them (Configs::loadAll(), ADR 0085): every key missing, malformed
 * or refused by its class, each by its variable and never its value. The
 * framework's own -- TINVAR_TRUSTED_PROXIES and its secret, the domains,
 * the profile -- with them.
 */
final readonly class ConfigCheck implements DoctorCheck
{
    public function title(): string
    {
        return 'Config';
    }

    public function run(DoctorContext $context): array
    {
        $configs = [FrameworkConfig::class];
        try {
            $classes = $context->classes();
        } catch (DiscoveryFailed $e) {
            return [Finding::problem(
                "Discovery refused the app's classes, so its config cannot be read:\n" . implode("\n", $e->problems),
                'Fix each class it names; `' . $context->console . ' check` names them too.',
            )];
        }
        if ($classes !== null) {
            $configs = array_values(array_unique([...$configs, ...$classes->configs]));
        }

        $findings = [];
        foreach ($configs as $class) {
            try {
                Configs::load($class, $context->env);
            } catch (ConfigException $e) {
                foreach ($e->problems as $problem) {
                    $findings[] = Finding::problem($problem, "Set or correct the variable it names, then `{$context->console} config` shows where each value comes from.");
                }
            }
        }

        return $findings === []
            ? [Finding::passed(\count($configs) . ' config ' . (\count($configs) === 1 ? 'class loads' : 'classes load') . ' as the boot loads them.')]
            : $findings;
    }
}
