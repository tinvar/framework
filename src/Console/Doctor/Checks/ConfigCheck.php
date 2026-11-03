<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\EnvironmentFile;
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
 *
 * And each key a class reads as a Secret whose variable `.env` sets: a
 * variable is kept out of `.env` by its name alone (EnvironmentFile), so
 * a secret whose name does not say so -- a store's DSN under `_STORE`, a
 * provider's headers -- is read from inside the project, where a copy or
 * a commit carries it away. Said by its variable, never its value.
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

        if ($findings === []) {
            $findings[] = Finding::passed(\count($configs) . ' config ' . (\count($configs) === 1 ? 'class loads' : 'classes load') . ' as the boot loads them.');
        }

        return [...$findings, ...self::secretsInEnvFile($context, $configs)];
    }

    /**
     * A warning for each Secret key whose variable `.env` sets, which its
     * name did not keep out.
     *
     * @param list<class-string> $configs
     * @return list<Finding>
     */
    private static function secretsInEnvFile(DoctorContext $context, array $configs): array
    {
        $env = "{$context->root}/.env";
        if (!is_file($env)) {
            return [];
        }
        try {
            $set = EnvironmentFile::parse((string) file_get_contents($env), '.env')['values'];
        } catch (ConfigException) {
            return []; // SecretsCheck reports the line.
        }

        $findings = [];
        foreach ($configs as $class) {
            foreach (Configs::keys($class) as $key) {
                if (!$key->secret() || ($set[$key->variable] ?? '') === '') {
                    continue;
                }
                $findings[] = Finding::warning(
                    "{$key->variable} is a secret ({$class}::\${$key->parameter}) with a value in .env, inside the project, where a copy, an archive or a commit carries it away: "
                    . 'its name does not end in ' . implode(', ', EnvironmentFile::SECRET_SUFFIXES) . ", so {$context->console} reads it from there.",
                    "Move it to a file outside the project, readable by you alone (chmod 600), name that file in {$key->variable}_FILE in .env, and delete the line; or export {$key->variable} in your shell.",
                );
            }
        }

        return $findings;
    }
}
