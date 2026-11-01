<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Config\ConfigException;
use Tinvar\Config\EnvironmentFile;
use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Console\Doctor\SafeFix;
use Tinvar\Console\Secrets\SecretFiles;
use Tinvar\Support\AppKey;

/**
 * The app key and the probe token: each given by its variable, or in its
 * file outside the project, and long enough -- and no secret left in the
 * project's `.env`, which is never read for one (EnvironmentFile).
 *
 * On a developer's machine a missing key or token is generated the first
 * time the app needs it, so it is a warning `--fix` generates now. On a
 * server it is a problem: there each belongs in the platform's secret
 * store, and two servers that each generated their own would refuse each
 * other's sessions and tokens.
 */
final readonly class SecretsCheck implements DoctorCheck
{
    public function title(): string
    {
        return 'The app key and the probe token';
    }

    public function run(DoctorContext $context): array
    {
        $findings = [
            ...$this->secret($context, SecretFiles::APP_KEY, 'The app key', $context->keyFile),
            ...$this->secret($context, SecretFiles::PROBE_TOKEN, 'The probe token', $context->probeTokenFile),
        ];

        $env = "{$context->root}/.env";
        if (is_file($env)) {
            try {
                $secrets = EnvironmentFile::parse((string) file_get_contents($env), '.env')['secrets'];
            } catch (ConfigException $e) {
                $findings[] = Finding::problem(implode("\n", $e->problems), 'Correct the line it names in .env; bin/tinvar refuses to start with it.');
                $secrets = [];
            }
            foreach ($secrets as $variable) {
                $findings[] = Finding::warning(
                    "{$variable} has a value in .env, inside the project, where a copy, an archive or a commit carries it away. bin/tinvar never reads a secret from there.",
                    "Move it to a file outside the project, readable by you alone (chmod 600), name that file in {$variable}_FILE in .env, and delete the line; or export {$variable} in your shell.",
                );
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function secret(DoctorContext $context, string $variable, string $what, ?string $file): array
    {
        $production = $context->production();
        $given = $context->variable($variable);
        if ($given !== null) {
            $length = \strlen(trim($given));

            return $length < AppKey::MIN_LENGTH
                ? [Finding::problem("{$what} in {$variable} is {$length} characters; it must be " . AppKey::MIN_LENGTH . ' or more, or every token signed with it can be guessed offline.', "Set {$variable} to `bin/tinvar key:generate --show`'s output, in the environment or the platform's secret store.")]
                : [Finding::passed("{$what} is given by {$variable}.")];
        }

        $configured = $context->variable("{$variable}_FILE");
        if ($configured !== null && !str_starts_with($configured, '/')) {
            return [Finding::problem("{$variable}_FILE is {$configured}, which is not an absolute path: each process would look for it in its own directory.", "Set {$variable}_FILE to an absolute path outside the project.")];
        }
        if ($file === null) {
            return [Finding::problem("{$what} has no variable, and this user has no state directory to keep one in.", "Set {$variable}, or {$variable}_FILE to an absolute path outside the project; or set HOME or XDG_STATE_HOME.")];
        }
        if (is_file($file)) {
            $length = \strlen(trim((string) (is_readable($file) ? file_get_contents($file) : '')));

            return $length < AppKey::MIN_LENGTH
                ? [Finding::problem("{$what} in {$file} is {$length} characters; it must be " . AppKey::MIN_LENGTH . ' or more.', "Delete {$file} and run `{$context->console} key:generate`, which writes a new one; everything signed with the old one stops working.")]
                : [Finding::passed("{$what} is in {$file}.")];
        }

        if ($production !== null) {
            return [Finding::problem(
                "{$what} is not set, and {$production}: a server never generates its own, or two servers would sign with two keys.",
                "Set {$variable} from the platform's secret store (`{$context->console} key:generate --show` prints a new one), or {$variable}_FILE to a mounted secret file; `{$context->console} deploy:init <target>` writes how for each platform.",
            )];
        }

        return [Finding::warning(
            "{$what} has not been generated yet; the app makes {$file} the first time it needs it.",
            "`{$context->console} key:generate`, or `{$context->console} doctor --fix`.",
            new SafeFix("generate {$file}", static fn (): string => SecretFiles::ensure($variable, $file, $context->env) . " {$file}"),
        )];
    }
}
