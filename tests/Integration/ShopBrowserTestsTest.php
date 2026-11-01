<?php

declare(strict_types=1);

/**
 * The shop behind the browser test endpoint (ADR 0066): its scenarios, a
 * sign-in through its own sessions, its clock moved and put back -- and
 * every way Kernel::serving() refuses to put the endpoint in front of it.
 *
 * Pages are answered without the renderer, through handleWith(), which is
 * the whole PHP request path up to the render: a page reached is a page
 * whose middleware let the request through.
 */

namespace Tinvar\Tests\Integration\ShopBrowserTests;

use Shop\Support\Clock;
use Shop\Kernel;
use Shop\Support\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\PdoConnection;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Testing\Browser\BrowserTestRun;
use Tinvar\Testing\Browser\BrowserTests;
use Tinvar\Testing\Fakes\MailFake;

const KEY = 'shop-browser-tests-key-of-32-bytes!!';

/** What the current test is served: the endpoint, the Kernel behind it, the run's token, the database. */
final class Served
{
    public static BrowserTests $tests;
    public static Kernel $kernel;
    public static string $token;
    public static string $run;
    public static string $database;

    /** @var array<string, string|false> the environment as it was before the test */
    public static array $environment = [];
}

/**
 * The environment a run's server has, which the shop's clock reads before
 * it moves: put back after each test.
 *
 * @param array<string, string|null> $env
 */
function environment(array $env): void
{
    foreach ($env as $name => $value) {
        Served::$environment[$name] ??= getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }
}

beforeEach(function () {
    Served::$database = Shop::testDatabase();
    ['directory' => Served::$run, 'token' => Served::$token] = BrowserTestRun::prepare();
    $mail = new MailFake();
    Served::$kernel = Kernel::fromEnvironment(
        '/nonexistent/renderer.sock',
        key: KEY,
        database: Served::$database,
        mailTransport: $mail,
        mailRenderer: $mail,
    );
    environment(['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing', 'TINVAR_TEST_RUN' => Served::$run]);
    Served::$tests = BrowserTests::around(Served::$kernel);
});

afterEach(function () {
    // Back to the system's time, for the next test in this process.
    Clock::move(0);
    foreach (Served::$environment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }
    Served::$environment = [];
    BrowserTestRun::remove(Served::$run);
});

/**
 * A helper call, as the runner makes it.
 *
 * @param array<string, mixed> $body
 * @return array{int, array<string, mixed>}
 */
function helper(string $name, array $body = []): array
{
    $response = Served::$tests->handle(
        new Request('POST', "/_test/{$name}", [
            'host' => '127.0.0.1:8080',
            'content-type' => 'application/json',
            'x-tinvar-test-token' => Served::$token,
        ], remoteAddress: '127.0.0.1'),
        json_encode((object) $body, JSON_THROW_ON_ERROR),
    );
    $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
    \assert(\is_array($decoded));

    /** @var array<string, mixed> $decoded */
    return [$response->status, $decoded];
}

/**
 * A page through the whole request path up to the render: 200 "reached"
 * when its middleware let the request through, and whatever they answered
 * otherwise.
 *
 * @param array<string, string> $cookies
 */
function page(string $path, array $cookies): Response
{
    return Served::$kernel->handleWith(
        new Request('GET', $path, ['host' => '127.0.0.1:8080'], cookies: $cookies, remoteAddress: '127.0.0.1'),
        static fn (LoadedRoute $loaded, Request $request): Response => Response::make(200, 'reached'),
    );
}

/** @return array<string, string> the cookies the endpoint signed the user in with */
function signIn(string $user): array
{
    [$status, $body] = helper('sign-in', ['user' => $user]);
    expect($status)->toBe(200);
    $cookies = $body['cookies'];
    \assert(\is_array($cookies));

    /** @var array<string, string> $cookies */
    return $cookies;
}

it('seeds a customer of its own, and signs them in through the shop\'s sessions', function () {
    [$status, $made] = helper('seed', ['scenario' => 'customer', 'input' => ['name' => 'Ada Tester']]);
    expect($status)->toBe(200)
        ->and($made['users'])->toHaveCount(1)
        ->and($made['email'])->toMatch('/^browser-[0-9a-f]{16}@shop\.test$/')
        ->and($made['name'])->toBe('Ada Tester')
        ->and($made)->not->toHaveKey('password');
    $user = $made['users'][0];

    // Signed out, the orders page sends the visitor to sign in.
    expect(page('/orders', [])->status)->toBeGreaterThanOrEqual(300)->toBeLessThan(400);

    $cookies = signIn($user);
    expect(array_keys($cookies))->toBe([Kernel::SESSION_COOKIE])
        ->and(page('/orders', $cookies)->body())->toBe('reached');

    // A session row, as signing in writes one, and the customer's epoch
    // moved on, as signing in moves it.
    $database = PdoConnection::sqlite(Served::$database, 'shop');
    expect($database->select('SELECT COUNT(*) AS n FROM sessions WHERE customer_id = ?', [$user])[0]['n'] ?? null)->toBe(1)
        ->and($database->select('SELECT epoch FROM auth_epochs WHERE customer_id = ?', [$user])[0]['epoch'] ?? 0)->toBeGreaterThan(0);
});

it('signs in the demo account, which its scenario names and writes nothing for', function () {
    [, $made] = helper('seed', ['scenario' => 'demo']);
    expect($made['email'])->toBe('demo@shop.test');

    $users = $made['users'];
    \assert(\is_array($users) && \is_string($users[0]));
    expect(page('/orders', signIn($users[0]))->body())->toBe('reached');
});

it('has no scenario that names the staff account, and signs it in under no other', function () {
    expect(helper('seed', ['scenario' => 'staff'])[0])->toBe(422);

    $staff = PdoConnection::sqlite(Served::$database, 'shop')
        ->select('SELECT id FROM customers WHERE email = ?', [StaffAccount::EMAIL])[0]['id'] ?? null;
    expect($staff)->toBeString();

    [$status] = helper('sign-in', ['user' => $staff]);
    expect($status)->toBe(403);
});

it('runs a session out by moving the clock, and puts it back', function () {
    [, $made] = helper('seed', ['scenario' => 'customer']);
    $users = $made['users'];
    \assert(\is_array($users) && \is_string($users[0]));
    $cookies = signIn($users[0]);

    // A day on: the session, twelve hours long, is over -- read through
    // the cookie's expiry and the row's, both on the shop's clock.
    [$status, $clock] = helper('clock', ['travel' => 86_400]);
    expect($status)->toBe(200)
        ->and($clock['offset'])->toBe(86_400)
        ->and(Clock::now())->toBeGreaterThanOrEqual(time() + 86_400);
    expect(page('/orders', $cookies)->status)->toBeGreaterThanOrEqual(300)->toBeLessThan(400);

    // Back, and it is still the session it was.
    helper('reset');
    expect(Clock::now())->toBeLessThanOrEqual(time())
        ->and(page('/orders', $cookies)->body())->toBe('reached');
});

it('dates what it writes on the moved clock', function () {
    helper('clock', ['at' => 1_893_456_000]);
    expect(Clock::date()->format('Y-m-d'))->toBe('2030-01-01');
});

// ------------------------------------------------------------ refusals

it('seeds no scenario where the environment says production', function () {
    environment(['APP_ENV' => 'production']);
    Served::$kernel->seed('customer', []);
})->throws(\LogicException::class, 'APP_ENV=production');

it('moves the clock under the test profile alone', function () {
    environment(['TINVAR_PROFILE' => null, 'TINVAR_TEST_RUN' => null]);
    Clock::move(3600);
})->throws(\LogicException::class, 'only under the test profile');

/**
 * Kernel::serving() under $env, the rest of the environment as it was.
 *
 * @param array<string, string|null> $env
 */
function serving(array $env): void
{
    $before = [];
    foreach ($env as $name => $value) {
        $before[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }
    try {
        Kernel::serving();
    } finally {
        foreach ($before as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

/** A build directory whose profile record says $profile. */
function build(string $profile): string
{
    $directory = PrivateDirectory::create('profile');
    mkdir("{$directory}/rsc");
    file_put_contents("{$directory}/rsc/profile.json", json_encode(['format' => 1, 'profile' => $profile], JSON_THROW_ON_ERROR));
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            unlink("{$directory}/rsc/profile.json");
            rmdir("{$directory}/rsc");
            PrivateDirectory::remove($directory);
        }
    });

    return $directory;
}

it('refuses the test profile where nothing says this is not production', function () {
    serving(['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => null, 'APP_ENV' => null, 'TINVAR_BUILD_DIR' => build('test')]);
})->throws(\RuntimeException::class, 'nothing says this is not production');

// As the image runs: TINVAR_ENV=production is in its Dockerfile.
it('refuses the test profile in production', function (string $marker) {
    serving(['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing', $marker => 'production', 'TINVAR_BUILD_DIR' => build('test')]);
})->with(['TINVAR_ENV', 'APP_ENV', 'NODE_ENV'])->throws(\RuntimeException::class, '=production');

it('refuses the test profile with a build made for production', function () {
    serving(['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing', 'TINVAR_BUILD_DIR' => build('production')]);
})->throws(\RuntimeException::class, 'was made for production');

it('refuses a test build without the test profile', function () {
    serving(['TINVAR_PROFILE' => null, 'TINVAR_TEST_RUN' => null, 'TINVAR_BUILD_DIR' => build('test')]);
})->throws(\RuntimeException::class, 'was made with --profile=test');

it('refuses a run named without the test profile', function () {
    serving(['TINVAR_PROFILE' => null, 'TINVAR_TEST_RUN' => Served::$run, 'TINVAR_BUILD_DIR' => build('production')]);
})->throws(\RuntimeException::class, 'TINVAR_TEST_RUN is set');

it('refuses the test profile with no run named', function () {
    serving([
        'TINVAR_PROFILE' => 'test',
        'TINVAR_ENV' => 'testing',
        'TINVAR_TEST_RUN' => null,
        'TINVAR_BUILD_DIR' => build('test'),
        'TINVAR_APP_KEY' => KEY,
        'SHOP_DATABASE' => Served::$database,
        'SHOP_LOG' => Served::$database . '.log',
    ]);
})->throws(\RuntimeException::class, 'no TINVAR_TEST_RUN names the run');

it('serves the Kernel alone under the production profile', function () {
    environment([
        'TINVAR_PROFILE' => null,
        'TINVAR_TEST_RUN' => null,
        'TINVAR_BUILD_DIR' => build('production'),
        'TINVAR_APP_KEY' => KEY,
        'SHOP_DATABASE' => Served::$database,
        'SHOP_LOG' => Served::$database . '.log',
    ]);

    expect(Kernel::serving())->toBeInstanceOf(Kernel::class);
});

it('finds no endpoint on the Kernel itself', function () {
    [, $made] = helper('seed', ['scenario' => 'customer']);
    $users = $made['users'];
    \assert(\is_array($users) && \is_string($users[0]));

    $response = Served::$kernel->handleWith(
        new Request('POST', '/_test/sign-in', [
            'host' => '127.0.0.1:8080',
            'content-type' => 'application/json',
            'x-tinvar-test-token' => Served::$token,
        ], remoteAddress: '127.0.0.1'),
        static fn (): Response => Response::make(200, 'rendered'),
        json_encode(['user' => $users[0]], JSON_THROW_ON_ERROR),
    );

    expect($response->status)->not->toBe(200)
        ->and($response->body())->not->toContain(Kernel::SESSION_COOKIE);
});

it('is not a customer the endpoint signs in once the scenario\'s row is gone', function () {
    [, $made] = helper('seed', ['scenario' => 'customer']);
    $users = $made['users'];
    \assert(\is_array($users) && \is_string($users[0]));
    PdoConnection::sqlite(Served::$database, 'shop')->execute('DELETE FROM customers WHERE id = ?', [$users[0]]);
    Served::$kernel->reset();

    expect(helper('sign-in', ['user' => $users[0]])[0])->toBe(404);
});

/**
 * Runs $command with $env alone beside PATH, and returns its exit status
 * and what it wrote.
 *
 * @param list<string> $command
 * @param array<string, string> $env
 * @return array{int, string}
 */
function run(array $command, array $env): array
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ...$env,
    ]);
    \assert(\is_resource($process));
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

// The image's start: bin/serve --prebuilt, behind the container's
// entrypoint. Each refuses before it starts or touches anything.
it('has bin/serve refuse the test profile and a test run, prebuilt or not', function (string $argument, string $name, string $value) {
    [$status, $output] = run([Shop::root() . '/bin/serve', ...($argument === '' ? [] : [$argument])], [$name => $value, 'TINVAR_ENV' => 'testing']);

    expect($status)->toBe(64)
        ->and($output)->toContain($name);
})->with([
    'the test profile, prebuilt' => ['--prebuilt', 'TINVAR_PROFILE', 'test'],
    'the test profile' => ['', 'TINVAR_PROFILE', 'test'],
    'a test run, prebuilt' => ['--prebuilt', 'TINVAR_TEST_RUN', '/tmp/tinvar-test-run'],
    'a build elsewhere' => ['', 'TINVAR_BUILD_DIR', '/tmp/dist-test'],
]);

it('has the container\'s entrypoint refuse the test profile and a test run', function (string $name, string $value) {
    [$status, $output] = run(['bash', \dirname(__DIR__, 2) . '/resources/container/entrypoint'], [$name => $value]);

    expect($status)->toBe(78)
        ->and($output)->toContain($name);
})->with([
    'the test profile' => ['TINVAR_PROFILE', 'Test'],
    'a test run' => ['TINVAR_TEST_RUN', '/tmp/tinvar-test-run'],
    'a build elsewhere' => ['TINVAR_BUILD_DIR', '/tmp/dist-test'],
]);

// And the two-container shape's PHP side (ADR 0071), first of all its checks.
it('has the pod\'s PHP container refuse the test profile and a test run', function (string $name, string $value) {
    [$status, $output] = run(['bash', \dirname(__DIR__, 2) . '/resources/container/pod-php'], [$name => $value]);

    expect($status)->toBe(78)
        ->and($output)->toContain($name);
})->with([
    'the test profile' => ['TINVAR_PROFILE', 'Test'],
    'a test run' => ['TINVAR_TEST_RUN', '/tmp/tinvar-test-run'],
    'a build elsewhere' => ['TINVAR_BUILD_DIR', '/tmp/dist-test'],
]);

// The shop's Caddyfile binds SHOP_BIND_ALSO beside --host.
it('has tinvar serve refuse the test profile on any address SHOP_BIND_ALSO adds but loopback', function () {
    [$status, $output] = run(
        [PHP_BINARY, \dirname(Shop::root(), 2) . '/bin/tinvar', 'serve', '--app=' . Shop::root()],
        ['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing', 'SHOP_BIND_ALSO' => '::1 0.0.0.0'],
    );

    expect($status)->toBe(64)
        ->and($output)->toContain('SHOP_BIND_ALSO names 0.0.0.0');
});
