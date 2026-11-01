<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

use Tinvar\Config\Configs;
use Tinvar\Config\EnvironmentFile;
use Tinvar\Mail\MailProvider;
use Tinvar\Mail\MailTransports;

/**
 * The `.env` `tinvar new` writes for the developer's machine (ADR 0150):
 * the settings it asked about, read into the environment by the app's
 * bin/tinvar there and nowhere else (Tinvar\Config\EnvironmentFile), git
 * ignoring it. No secret is ever in it: a secret's variable names a file
 * outside the project, in its _FILE variable.
 */
final class DevEnvironment
{
    /**
     * The file's text.
     *
     * @param string|null $databasePasswordFile where the database password was generated, outside the project
     */
    public static function contents(NewAppAnswers $answers, ?string $databasePasswordFile = null): string
    {
        $lines = [
            "# This machine's settings for {$answers->name()}, written by `tinvar new`.",
            '#',
            '# bin/tinvar reads this into its environment before anything reads config,',
            '# here and nowhere else: never where TINVAR_ENV says production or staging,',
            '# never over a variable the environment already sets, and never a secret --',
            '# a variable ending in _KEY, _SECRET, _PASSWORD or _TOKEN is left out. Put a',
            '# secret in a file outside the project, readable by you alone, and name the',
            '# file in its _FILE variable. git ignores this file; .env.example lists the',
            '# rest. `bin/tinvar doctor` checks it all.',
            '',
            '# A developer\'s machine: db:seed and migrate:fresh may run here.',
            'TINVAR_ENV=local',
            '',
        ];

        if ($answers->database->isServer()) {
            $lines = [...$lines,
                "# The {$answers->database->title()} database. Create the user and the database (bin/tinvar doctor",
                '# says whether it can reach them), then `bin/tinvar migrate`.',
                "APP_DATABASE_DRIVER={$answers->database->value}",
                "APP_DATABASE_HOST={$answers->databaseHost}",
                'APP_DATABASE_PORT=' . ($answers->databasePort ?? $answers->database->defaultPort()),
                'APP_DATABASE_NAME=' . ($answers->databaseName !== '' ? $answers->databaseName : $answers->identifier()),
                'APP_DATABASE_USER=' . ($answers->databaseUser !== '' ? $answers->databaseUser : $answers->identifier()),
                ...($databasePasswordFile !== null ? ['# Its password, generated outside the project.', "APP_DATABASE_PASSWORD_FILE={$databasePasswordFile}"] : []),
                '',
            ];
        } else {
            $lines = [...$lines, '# The SQLite database: database/app.sqlite unless APP_DATABASE says.', '# APP_DATABASE=', ''];
        }

        if ($answers->mail === MailProvider::Log) {
            $lines = [...$lines, '# Mail: written to a log on this machine. Name a provider to send it:', '# smtp, ses, postmark, mailgun, resend or sendgrid.', '# TINVAR_MAIL_PROVIDER=', ''];
        } else {
            $lines = [...$lines, "# Mail goes through {$answers->mail->value}. Its keys go in files outside the project:", "TINVAR_MAIL_PROVIDER={$answers->mail->value}"];
            foreach (self::mailLines($answers->mail) as $line) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        if ($answers->tenancy === TenantLookup::Subdomain) {
            $lines = [...$lines,
                '# The app\'s own host: each tenant is a label under it. Browsers send',
                '# acme.localhost to this machine, so http://acme.localhost:PORT is acme\'s.',
                'TINVAR_APP_DOMAIN=localhost',
                '',
            ];
        }

        return implode("\n", $lines);
    }

    /**
     * The commented lines naming a provider's variables, each as it may be
     * set here: a secret by the file its _FILE variable names, a variable
     * named like a secret that reads no file by the shell's environment
     * (this file never sets one), the rest as it is.
     *
     * @return list<string>
     */
    public static function mailLines(MailProvider $provider): array
    {
        $files = [];
        foreach (MailTransports::configs() as $class) {
            foreach (Configs::keys($class) as $key) {
                if ($key->fileVariable() !== null) {
                    $files[$key->variable] = true;
                }
            }
        }
        $lines = [];
        foreach (array_keys(self::mailVariables($provider)) as $variable) {
            $lines[] = match (true) {
                isset($files[$variable]) => "# {$variable}_FILE=",
                EnvironmentFile::namesSecret($variable) => "# {$variable}: export it in your shell; this file never sets a variable named like a secret",
                default => "# {$variable}=",
            };
        }

        return $lines;
    }

    /**
     * A provider's variables, each marked whether it is a secret.
     *
     * @return array<string, bool>
     */
    public static function mailVariables(MailProvider $provider): array
    {
        return match ($provider) {
            MailProvider::Smtp => ['TINVAR_SMTP_HOST' => false, 'TINVAR_SMTP_PORT' => false, 'TINVAR_SMTP_USERNAME' => false, 'TINVAR_SMTP_PASSWORD' => true],
            MailProvider::Ses => ['TINVAR_SES_REGION' => false, 'TINVAR_SES_KEY' => true, 'TINVAR_SES_SECRET' => true],
            MailProvider::Postmark => ['TINVAR_POSTMARK_TOKEN' => true],
            MailProvider::Mailgun => ['TINVAR_MAILGUN_DOMAIN' => false, 'TINVAR_MAILGUN_REGION' => false, 'TINVAR_MAILGUN_KEY' => true],
            MailProvider::Resend => ['TINVAR_RESEND_KEY' => true],
            MailProvider::SendGrid => ['TINVAR_SENDGRID_KEY' => true],
            MailProvider::Log, MailProvider::Array => [],
        };
    }
}
