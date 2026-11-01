<?php

declare(strict_types=1);

/**
 * The shop's throttles under php -S, the front controller's documented
 * mode, where each request starts from nothing: without APCu they counted
 * in a store that was new on every request, so no limit ever ran out.
 */

namespace Tinvar\Tests\Integration\PhpServerThrottle;

use Shop\Auth\Account\SignInThrottle;
use Shop\Database\Seeders\Seeder;
use Shop\Support\DemoAccount;
use Shop\Kernel;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Orm\PdoConnection;
use Tinvar\Server\PortFinder;
use Tinvar\Support\Signer;

const KEY = 'php-server-throttle-test-key-32-bytes!';

it('keeps counting sign-in failures from one request to the next', function () {
    $root = \dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/tinvar-phps-throttle-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $database = "{$directory}/shop.sqlite";
    new Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 1);

    $port = new PortFinder()->find('127.0.0.1');
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$root}/examples/shop/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$directory}/server.log", 'w']],
        $pipes,
        $root,
        [
            ...array_diff_key(getenv(), ['TINVAR_THROTTLE' => true]),
            'TINVAR_APP_KEY' => KEY,
            'TINVAR_SOCKET' => "{$directory}/no-renderer.sock",
            'SHOP_DATABASE' => $database,
        ],
    );

    try {
        set_error_handler(static fn (): bool => true);
        try {
            $deadline = microtime(true) + 10;
            while (fsockopen('127.0.0.1', $port) === false && microtime(true) < $deadline) {
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }

        // A session and its token, as the sign-in page would have issued them.
        $cookie = Kernel::newSession(KEY);
        $token = (string) new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), static fn (): string => (string) Kernel::sessionFrom($cookie, KEY))
            ->for(Request::get('/'));
        $signIn = static function (string $password) use ($port, $cookie, $token): int {
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'content-type: application/json',
                    'accept: application/json',
                    "x-xsrf-token: {$token}",
                    'cookie: ' . Kernel::SESSION_COOKIE . "={$cookie}; __Host-XSRF-TOKEN={$token}",
                ]),
                'content' => json_encode(['email' => DemoAccount::EMAIL, 'password' => $password, 'next' => '/']),
            ]]);
            file_get_contents("http://127.0.0.1:{$port}/_action/account.signIn", false, $context);

            return (int) explode(' ', $http_response_header[0] ?? '')[1];
        };

        $wrong = [];
        for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
            $wrong[] = $signIn("guess-{$i}");
        }

        expect(array_unique($wrong))->toBe([422])
            ->and($signIn(DemoAccount::PASSWORD))->toBe(429, 'the budget was spent across ten requests');
    } finally {
        proc_terminate($server);
        proc_close($server);
        foreach (glob("{$directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
