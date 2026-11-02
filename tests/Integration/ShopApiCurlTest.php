<?php

declare(strict_types=1);

/**
 * The shop's order API end to end (ADR 0155), as a customer's script calls
 * it: the shop served by PHP's server, a customer signing in and making a
 * token on the settings page's action, and then curl -- nothing of the
 * framework's on the calling side -- reading their orders with it, and
 * being refused where a session is read.
 */

namespace Tinvar\Tests\Integration\ShopApiCurl;

use Shop\Database\Seeders\Seeder;
use Shop\Kernel;
use Shop\Support\DemoAccount;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Orm\PdoConnection;
use Tinvar\Server\PortFinder;
use Tinvar\Support\Signer;

const KEY = 'shop-api-curl-test-key-of-32-bytes!!';

/**
 * One curl call: its status, its headers lowercased, and its body.
 *
 * @param list<string> $arguments curl's own, after the URL
 * @return array{int, array<string, string>, string}
 */
function curl(string $url, array $arguments = []): array
{
    $directory = sys_get_temp_dir() . '/tinvar-curl-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    try {
        $command = ['curl', '-sS', '--max-time', '20', '-o', "{$directory}/body", '-D', "{$directory}/headers", '-w', '%{http_code}', $url, ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        \assert(\is_resource($process));
        $status = (int) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        if ($status === 0) {
            throw new \RuntimeException("curl reached nothing at {$url}: {$error}");
        }
        $headers = [];
        foreach (explode("\r\n", (string) file_get_contents("{$directory}/headers")) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                // Every cookie set, one after another.
                $headers[$name] = isset($headers[$name]) && $name === 'set-cookie' ? "{$headers[$name]}\n" . trim($value) : trim($value);
            }
        }

        return [$status, $headers, (string) file_get_contents("{$directory}/body")];
    } finally {
        foreach (glob("{$directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

it('lets a customer\'s script read their orders with a token over curl, and nothing else', function () {
    $root = \dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/tinvar-api-curl-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $database = "{$directory}/shop.sqlite";
    new Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 2);

    $port = new PortFinder()->find('127.0.0.1');
    $origin = "http://127.0.0.1:{$port}";
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$root}/examples/shop/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$directory}/server.log", 'w']],
        $pipes,
        $root,
        [
            ...getenv(),
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

        // The customer's browser: a session and its CSRF token, as the
        // sign-in page would have issued them; then signing in and making a
        // token on the settings page's action, as its form posts.
        $cookie = Kernel::newSession(KEY);
        $csrf = static function (string $session): string {
            return (string) new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), static fn (): string => (string) Kernel::sessionFrom($session, KEY))->for(Request::get('/'));
        };
        $post = static function (string $action, array $input, string $session) use ($origin, $csrf): array {
            $token = $csrf($session);

            return curl("{$origin}/_action/{$action}", [
                '-X', 'POST',
                '-H', 'content-type: application/json',
                '-H', 'accept: application/json',
                '-H', "x-xsrf-token: {$token}",
                '-b', Kernel::SESSION_COOKIE . "={$session}; __Host-XSRF-TOKEN={$token}",
                '--data', json_encode($input, JSON_THROW_ON_ERROR),
            ]);
        };

        [$status, $headers] = $post('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/'], $cookie);
        // A redirect to where it was going, with the new session.
        expect($status)->toBe(303);
        preg_match('/' . preg_quote(Kernel::SESSION_COOKIE, '/') . '=([^;]+)/', $headers['set-cookie'] ?? '', $m);
        $session = $m[1] ?? throw new \LogicException('Signing in set no session cookie.');

        [$status, , $body] = $post('account.createApiToken', ['name' => 'curl', 'scopes' => ['orders:read'], 'lifetime' => '30'], $session);
        $token = json_decode($body, true)['value']['token'] ?? null;
        expect($status)->toBe(200)
            ->and($token)->toMatch('/^tvr_[0-9a-f]{32}_[0-9A-Za-z]{43}\z/');

        // The script: curl with the token, and nothing else.
        [$status, $headers, $body] = curl("{$origin}/_api/orders.yours", [
            '-X', 'POST',
            '-H', "Authorization: Bearer {$token}",
            '-H', 'Content-Type: application/json',
            '--data', '{"status": "all"}',
        ]);
        $orders = json_decode($body, true)['orders'] ?? null;
        expect($status)->toBe(200, $body)
            ->and($headers['content-type'] ?? null)->toBe('application/json')
            ->and($headers)->not->toHaveKey('set-cookie')
            ->and($orders)->toBeArray()->not->toBe([]);

        [$status, , $body] = curl("{$origin}/_api/orders.detail", [
            '-X', 'POST',
            '-H', "Authorization: Bearer {$token}",
            '-H', 'Content-Type: application/json',
            '--data', json_encode(['order' => $orders[0]['id']], JSON_THROW_ON_ERROR),
        ]);
        expect($status)->toBe(200)
            ->and(json_decode($body, true)['number'] ?? null)->toBe($orders[0]['number']);

        [$status, $headers, $body] = curl("{$origin}/api/orders/{$orders[0]['id']}/receipt.txt", ['-H', "Authorization: Bearer {$token}"]);
        expect($status)->toBe(200)
            ->and($body)->toContain("Receipt for order {$orders[0]['number']}")
            ->and($headers)->not->toHaveKey('set-cookie');

        // Refused: no token, a scope it lacks, and a session's own page.
        [$status, $headers] = curl("{$origin}/_api/orders.yours", ['-X', 'POST', '-H', 'Content-Type: application/json', '--data', '{}']);
        expect($status)->toBe(401)
            ->and($headers['www-authenticate'] ?? null)->toBe('Bearer realm="api"');

        [$status, $headers, $body] = curl("{$origin}/_api/orders.cancel", [
            '-X', 'POST',
            '-H', "Authorization: Bearer {$token}",
            '-H', 'Content-Type: application/json',
            '--data', json_encode(['order' => $orders[0]['id']], JSON_THROW_ON_ERROR),
        ]);
        expect($status)->toBe(403, $body)
            ->and(json_decode($body, true)['error']['code'] ?? null)->toBe('insufficient_scope')
            ->and($headers['www-authenticate'] ?? null)->toContain('scope="orders:cancel"');

        [$status, $headers, $body] = curl("{$origin}/orders", ['-H', "Authorization: Bearer {$token}", '-H', 'Accept: application/json']);
        expect($status)->toBe(401)
            ->and(json_decode($body, true)['error']['code'] ?? null)->toBe('api_token_not_accepted')
            ->and($headers)->not->toHaveKey('set-cookie');
    } finally {
        proc_terminate($server);
        proc_close($server);
        foreach (glob("{$directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->skip(trim((string) shell_exec('command -v curl')) === '', 'curl is not on the PATH.');
