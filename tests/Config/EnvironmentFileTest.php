<?php

declare(strict_types=1);

/**
 * A developer's .env, read into the environment before config is (ADR
 * 0157): never on a server, never over what is set, never a secret.
 */

use Tinvar\Config\ConfigException;
use Tinvar\Config\EnvironmentFile;

it('reads names and values, quoted or not, and leaves comments and secrets out', function () {
    $read = EnvironmentFile::parse(<<<'ENV'
        # The database
        APP_DATABASE_DRIVER=postgres
        export APP_DATABASE_HOST="127.0.0.1"
        APP_DATABASE_NAME='my app' # its name
        APP_LOG=/var/log/app.json # where
        APP_DATABASE_PASSWORD=hunter2
        APP_DATABASE_PASSWORD_FILE=/home/me/.local/state/tinvar/db-password
        APP_KEY=
        ENV);

    expect($read['values'])->toBe([
        'APP_DATABASE_DRIVER' => 'postgres',
        'APP_DATABASE_HOST' => '127.0.0.1',
        'APP_DATABASE_NAME' => 'my app',
        'APP_LOG' => '/var/log/app.json',
        'APP_DATABASE_PASSWORD_FILE' => '/home/me/.local/state/tinvar/db-password',
    ])->and($read['secrets'])->toBe(['APP_DATABASE_PASSWORD']);
});

it('refuses a line it cannot read by its number, never its text', function () {
    $problems = [];
    try {
        EnvironmentFile::parse("APP_A=1\nlowercase=no\nAPP_B=\"open\n");
    } catch (ConfigException $e) {
        $problems = $e->problems;
    }

    expect($problems)->toBe([
        '.env line 2 is not NAME=value: write a variable in capitals, an equals sign and its value, or make it a # comment.',
        '.env line 3 opens a quote it does not close.',
    ]);
});

it('fills only what the environment leaves unset, and nothing on a server', function () {
    $file = tempnam(sys_get_temp_dir(), 'env');
    $name = 'TINVAR_ENVFILE_TEST_' . strtoupper(bin2hex(random_bytes(3)));
    file_put_contents($file, "{$name}_A=from-file\n{$name}_B=from-file\n");
    putenv("{$name}_B=exported");

    try {
        expect(EnvironmentFile::load($file)['loaded'])->toBe(["{$name}_A"])
            ->and(getenv("{$name}_A"))->toBe('from-file')
            ->and(getenv("{$name}_B"))->toBe('exported');

        putenv("{$name}_A");
        putenv('TINVAR_ENV=production');
        expect(EnvironmentFile::load($file)['loaded'])->toBe([])
            ->and(getenv("{$name}_A"))->toBeFalse();
    } finally {
        putenv('TINVAR_ENV');
        putenv("{$name}_A");
        putenv("{$name}_B");
        unlink($file);
    }
});

it('says what makes an environment a server\'s', function () {
    expect(EnvironmentFile::refusedHere(['APP_ENV' => 'Production']))->toBe('APP_ENV=production')
        ->and(EnvironmentFile::refusedHere(['TINVAR_ENV' => 'staging']))->toBe('TINVAR_ENV=staging')
        ->and(EnvironmentFile::refusedHere(['TINVAR_PROFILE' => 'test']))->toBe('TINVAR_PROFILE=test')
        ->and(EnvironmentFile::refusedHere(['TINVAR_ENV' => 'local']))->toBeNull();
});

it('names as a secret every Secret the framework\'s config reads, so none is read from .env', function () {
    $classes = [
        Tinvar\Config\FrameworkConfig::class,
        Tinvar\Mail\MailConfig::class,
        Tinvar\Mail\SmtpConfig::class,
        Tinvar\Mail\DkimConfig::class,
        Tinvar\Mail\Providers\PostmarkConfig::class,
        Tinvar\Mail\Providers\MailgunConfig::class,
        Tinvar\Mail\Providers\SendGridConfig::class,
        Tinvar\Mail\Providers\SesConfig::class,
        Tinvar\Mail\Providers\ResendConfig::class,
        Tinvar\Search\MeilisearchConfig::class,
        Tinvar\Search\SearchConfig::class,
        Tinvar\Push\VapidConfig::class,
        Tinvar\Audit\AuditConfig::class,
    ];
    $secrets = [];
    foreach ($classes as $class) {
        foreach (Tinvar\Config\Configs::keys($class) as $key) {
            if ($key->secret()) {
                $secrets[] = $key->variable;
            }
        }
    }

    expect($secrets)->toContain('TINVAR_DUMP_MYSQL_DSN')
        ->and(array_values(array_filter($secrets, static fn (string $name): bool => !EnvironmentFile::namesSecret($name))))->toBe([])
        ->and(EnvironmentFile::parse("TINVAR_DUMP_MYSQL_DSN=mysql:host=db;user=u;password=hunter2\n")['secrets'])->toBe(['TINVAR_DUMP_MYSQL_DSN']);
});
