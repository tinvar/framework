<?php

declare(strict_types=1);

/**
 * The shop behind the TLS terminator its compose file and its pod put in
 * front (ADR 0134). Every visitor's connection is the terminator's, so
 * keyed on the peer, ten wrong passwords from anyone locked the account's
 * owner out, and thirty attempts a minute stopped every sign-in. With the
 * terminator pinned in TINVAR_TRUSTED_PROXIES, each visitor is counted by
 * the address the terminator saw.
 */

namespace Tinvar\Tests\Integration\ShopTrustedProxy;

use Shop\Auth\SignInThrottle;
use Shop\Database\Seeder;
use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\SqliteThrottleStore;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;

const KEY = 'shop-trusted-proxy-test-key-32-bytes!!!';

/** The terminator's address: the gateway of the network compose pins. */
const PROXY = '10.213.89.1';

/**
 * $work with the environment set as given -- null unsets -- and put back
 * after.
 *
 * @template T
 * @param array<string, string|null> $env
 * @param \Closure(): T $work
 * @return T
 */
function withEnv(array $env, \Closure $work): mixed
{
    $previous = [];
    foreach ($env as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }

    try {
        return $work();
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        Configs::forget();
    }
}

function shop(?string $proxies): Kernel
{
    $directory = sys_get_temp_dir() . '/tinvar-trusted-proxy-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $database = "{$directory}/shop.sqlite";
    new Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 1);

    return withEnv(
        ['TINVAR_TRUSTED_PROXIES' => $proxies, 'SHOP_THROTTLE_STORE' => null, 'TINVAR_THROTTLE' => null],
        static fn (): Kernel => Kernel::fromEnvironment(
            "{$directory}/no-renderer.sock",
            key: KEY,
            database: $database,
            throttleStore: new SqliteThrottleStore("{$database}-throttle"),
            signInStore: new SqliteThrottleStore("{$database}-sign-in"),
        ),
    );
}

/** A sign-in through the terminator, by the visitor it names: its status. */
function signIn(Kernel $kernel, string $visitor, string $password): int
{
    $cookie = Kernel::newSession(KEY);
    $token = (string) new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), static fn (): string => (string) Kernel::sessionFrom($cookie, KEY))
        ->for(Request::get('/'));

    return $kernel->handle(
        new Request(
            'POST',
            '/_action/account.signIn',
            [
                'host' => 'shop.test', 'content-type' => 'application/json', 'accept' => 'application/json',
                'x-xsrf-token' => $token, 'x-forwarded-for' => $visitor,
            ],
            [],
            [],
            [Kernel::SESSION_COOKIE => $cookie, '__Host-XSRF-TOKEN' => $token],
            null,
            PROXY,
        ),
        json_encode(['email' => DemoAccount::EMAIL, 'password' => $password, 'next' => '/'], JSON_THROW_ON_ERROR),
    )->status;
}

it('counts a guesser behind the terminator apart from the account\'s owner', function () {
    $kernel = shop(PROXY);

    $wrong = [];
    for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
        $wrong[] = signIn($kernel, '198.51.100.1', "guess-{$i}");
    }

    expect(array_unique($wrong))->toBe([422])
        ->and(signIn($kernel, '198.51.100.1', DemoAccount::PASSWORD))->toBe(429, 'the guesser is paused')
        ->and(signIn($kernel, '198.51.100.2', DemoAccount::PASSWORD))->not->toBe(429, 'the owner is not');
});

it('counted everyone behind the terminator as one caller without it', function () {
    $kernel = shop(null);

    for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
        signIn($kernel, '198.51.100.1', "guess-{$i}");
    }

    expect(signIn($kernel, '198.51.100.2', DemoAccount::PASSWORD))->toBe(429);
});

it('stops the boot on a list it cannot read', function () {
    expect(static fn () => shop('10.213.89.0/24,proxy.internal'))->toThrow(ConfigException::class);
});
