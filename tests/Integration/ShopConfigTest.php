<?php

declare(strict_types=1);

/**
 * The shop's settings through its #[Config] classes (ADR 0085): a boot
 * with a key missing or malformed stops before anything opens, naming
 * every variable and no value, and a secret can come from a mounted file.
 */

use Shop\Http\Middleware\WarehouseToken;
use Shop\Config\BuildsIn;
use Shop\Kernel;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;

/**
 * @param array<string, string|null> $env
 * @param \Closure(): mixed $body
 */
function withShopEnvironment(array $env, \Closure $body): mixed
{
    $saved = [];
    foreach ($env as $name => $value) {
        $saved[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }
    try {
        return $body();
    } finally {
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

it('declares its config as tinvar check holds it', function () {
    expect(Configs::check(Kernel::classes()->configs))->toBe([]);
});

it('refuses to boot on a malformed setting, naming every one and quoting none', function () {
    $token = str_repeat('w', 31);
    $boot = static function (): string {
        try {
            Kernel::fromEnvironment(database: ':memory:', key: str_repeat('k', 32));
        } catch (ConfigException $e) {
            return $e->getMessage();
        }

        throw new \LogicException('The shop booted.');
    };

    // Each class's keys are parsed first, every one; its own rules -- a
    // token's length -- run once they have all parsed.
    withShopEnvironment(['SHOP_WAREHOUSE_TOKEN' => $token, 'TINVAR_PROBE_TRUST_LOOPBACK' => 'yes', 'SHOP_BUILDS' => 'databse'], function () use ($boot, $token): void {
        expect($boot())
            ->toContain('TINVAR_PROBE_TRUST_LOOPBACK must be 1 or 0')
            ->toContain('SHOP_BUILDS must be one of file, database')
            ->not->toContain($token)
            ->not->toContain('databse');
    });
    withShopEnvironment(['SHOP_WAREHOUSE_TOKEN' => $token, 'TINVAR_PROBE_TRUST_LOOPBACK' => 'yes'], function () use ($boot, $token): void {
        expect($boot())
            ->toContain('SHOP_WAREHOUSE_TOKEN must be 32 characters or more')
            ->toContain('TINVAR_PROBE_TRUST_LOOPBACK must be 1 or 0')
            ->not->toContain($token);
    });
});

it('reads the warehouse token from a mounted file, and refuses it set both ways', function () {
    $directory = \Tinvar\Support\PrivateDirectory::create('shop-config');
    $token = str_repeat('t', 40);
    file_put_contents("{$directory}/warehouse-token", $token . "\n");
    try {
        withShopEnvironment(['SHOP_WAREHOUSE_TOKEN_FILE' => "{$directory}/warehouse-token", 'SHOP_BUILDS' => 'database'], function () use ($token): void {
            expect(WarehouseToken::fromEnvironment())->toBe($token)
                ->and(Kernel::config()->builds)->toBe(BuildsIn::Database);
        });
        withShopEnvironment(['SHOP_WAREHOUSE_TOKEN_FILE' => "{$directory}/warehouse-token", 'SHOP_WAREHOUSE_TOKEN' => $token], function (): void {
            expect(fn () => WarehouseToken::fromEnvironment())
                ->toThrow(ConfigException::class, 'SHOP_WAREHOUSE_TOKEN and SHOP_WAREHOUSE_TOKEN_FILE are both set');
        });
    } finally {
        \Tinvar\Support\PrivateDirectory::remove($directory);
    }
});

// ADR 0116: mail is DKIM-signed over SMTP where TINVAR_MAIL_DKIM_* say how,
// and a key that cannot sign stops the boot without being quoted.
it('signs its SMTP mail with the DKIM key the environment names, and refuses one that cannot sign', function () {
    $seed = base64_encode(str_repeat("\x07", 32));
    $dkim = static function (\Tinvar\Mail\Transport $transport): ?\Tinvar\Mail\DkimSigner {
        $value = new \ReflectionProperty($transport, 'dkim')->getValue($transport);

        return $value instanceof \Tinvar\Mail\DkimSigner ? $value : null;
    };

    withShopEnvironment([
        'TINVAR_MAIL_PROVIDER' => 'smtp', 'TINVAR_SMTP_HOST' => '127.0.0.1', 'TINVAR_SMTP_PORT' => '1025', 'TINVAR_SMTP_ENCRYPTION' => 'none',
        'TINVAR_MAIL_DKIM_DOMAIN' => 'pellwick.test',
        'TINVAR_MAIL_DKIM_SELECTOR' => 'mail2026',
        'TINVAR_MAIL_DKIM_PRIVATE_KEY' => $seed,
    ], function () use ($dkim): void {
        expect($dkim(Kernel::mailTransport(':memory:'))?->selector)->toBe('mail2026');
    });
    withShopEnvironment(['TINVAR_MAIL_PROVIDER' => 'smtp', 'TINVAR_SMTP_HOST' => '127.0.0.1', 'TINVAR_SMTP_PORT' => '1025', 'TINVAR_SMTP_ENCRYPTION' => 'none'], function () use ($dkim): void {
        expect($dkim(Kernel::mailTransport(':memory:')))->toBeNull();
    });

    withShopEnvironment(['TINVAR_MAIL_DKIM_DOMAIN' => 'pellwick.test', 'TINVAR_MAIL_DKIM_SELECTOR' => 'mail', 'TINVAR_MAIL_DKIM_PRIVATE_KEY' => 'c2VjcmV0'], function (): void {
        try {
            Kernel::fromEnvironment(database: ':memory:', key: str_repeat('k', 32));
            $said = 'booted';
        } catch (ConfigException $e) {
            $said = $e->getMessage();
        }

        expect($said)->toContain('TINVAR_MAIL_DKIM_* cannot sign')->not->toContain('c2VjcmV0');
    });
});

it('sends through the provider TINVAR_MAIL_PROVIDER names, to a log unless it names one, and refuses one missing its key', function () {
    withShopEnvironment(['TINVAR_MAIL_PROVIDER' => null], function (): void {
        expect(Kernel::mailTransport(':memory:'))->toBeInstanceOf(\Tinvar\Mail\LogTransport::class);
    });
    withShopEnvironment(['TINVAR_MAIL_PROVIDER' => 'resend,postmark', 'TINVAR_RESEND_KEY' => 're_live_4f9c2a1e8b7d6c5a', 'TINVAR_POSTMARK_TOKEN' => 'f00dbabe-1234-4abc-9def'], function (): void {
        expect(Kernel::mailTransport(':memory:'))->toBeInstanceOf(\Tinvar\Mail\FailoverTransport::class);
    });
    withShopEnvironment(['TINVAR_MAIL_PROVIDER' => 'ses', 'TINVAR_SES_REGION' => 'eu-west-1'], function (): void {
        try {
            Kernel::mailTransport(':memory:');
            $said = 'built';
        } catch (ConfigException $e) {
            $said = $e->getMessage();
        }

        expect($said)->toContain('TINVAR_SES_KEY is required')->toContain('TINVAR_SES_SECRET (or TINVAR_SES_SECRET_FILE) is required');
    });
});
