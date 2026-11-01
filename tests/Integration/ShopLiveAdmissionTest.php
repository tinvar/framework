<?php

declare(strict_types=1);

/**
 * The shop's live trade against a staff member taken out of the admin panel.
 *
 * Two layers, each tested alone: StaffMemberships::revoke() moves the
 * account's auth epoch, so the next trade is the 401 that purges the
 * client; and a staff row deleted around it, epoch untouched, still gets
 * the panel's topics refused, because every trade asks the panel's
 * membership check again.
 */

namespace Tinvar\Tests\Integration\ShopLiveAdmission;

use Shop\Auth\AuthEpochs;
use Shop\Auth\StaffMemberships;
use Shop\Auth\StaffPermission;
use Shop\Database\Seeders\Seeder;
use Shop\Kernel;
use Shop\StaffAccount;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveMinter;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\SubscribeEndpoint;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Render\TenantClaim;
use Tinvar\Support\Signer;

const KEY = 'shop-live-admission-key-of-32-bytes!';

function database(): string
{
    $path = sys_get_temp_dir() . '/tinvar-shop-live-' . bin2hex(random_bytes(4)) . '.sqlite';
    new Seeder(PdoConnection::sqlite($path, 'shop', create: true))->run(customers: 3);
    $owner = getmypid();
    register_shutdown_function(static function () use ($path, $owner): void {
        if (getmypid() !== $owner) {
            return;
        }
        foreach (['', '-wal', '-shm', '-throttle', '-throttle-wal', '-throttle-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    });

    return $path;
}

/** Staff, signed in, holding the live token the admin orders page would hand them. */
final class Staff
{
    /** @var array<string, string> */
    public array $cookies = [];

    public readonly Kernel $kernel;
    public readonly MemoryHub $hub;
    public readonly Orm $orm;
    public readonly string $id;

    public function __construct(string $database)
    {
        $this->hub = new MemoryHub();
        $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-live-none.sock', key: KEY, database: $database, hub: $this->hub);
        $this->orm = new Orm(PdoConnection::sqlite($database, 'shop'), new IdentityMap('shop'));
        $this->id = (string) $this->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [StaffAccount::EMAIL])[0]['id'];

        $this->keep($this->kernel->handle(new Request('GET', '/sign-in', ['accept' => 'text/html'])));
        $this->post('/_action/account.signIn', ['email' => StaffAccount::EMAIL, 'password' => StaffAccount::PASSWORD, 'next' => '/']);
    }

    /** The token a render of /admin/orders mints for this session, as the Kernel would bind it. */
    public function adminToken(): string
    {
        $live = LiveUpdates::fromKey(KEY, $this->hub, static fn (): string => '');

        return (string) new LiveMinter(
            signer: $live->signer,
            topics: $live->topics,
            route: 'admin.orders',
            params: [],
            panel: StaffMemberships::PANEL,
            tenant: TenantClaim::current($this->kernel->orm),
            build: Kernel::build(),
            sessionId: (string) Kernel::sessionFrom($this->cookies[Kernel::SESSION_COOKIE] ?? null, KEY),
            user: $this->id,
            authEpoch: new AuthEpochs($this->orm)->of($this->id),
        )->mint(["\0orders"]);
    }

    /**
     * @param list<string> $tokens
     * @return array{Response, array<string, mixed>}
     */
    public function trade(array $tokens): array
    {
        $response = $this->post(SubscribeEndpoint::PATH, ['tokens' => $tokens]);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        return [$response, $body];
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): Response
    {
        $token = (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', [], [], [], $this->cookies));

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                $path,
                ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
            ),
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    private function keep(Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

it('grants staff the admin orders\' topic, renewal after renewal', function () {
    $staff = new Staff(database());

    [$first, $body] = $staff->trade([$staff->adminToken()]);
    [$second, $renewed] = $staff->trade([$body['tokens'][0]]);

    expect($first->status)->toBe(200)
        ->and($body['topics'])->toHaveCount(1)
        ->and($second->status)->toBe(200)
        ->and($renewed['topics'])->toBe($body['topics']);
});

it('refuses a staff member taken out of the panel with the 401 that purges the client: the epoch moved', function () {
    $staff = new Staff(database());
    [, $body] = $staff->trade([$staff->adminToken()]);
    // Signing in took away whatever the browser was granted before (ADR 0069).
    $revoked = $staff->hub->revoked;

    new StaffMemberships($staff->orm)->revoke($staff->id);
    [$refused, $error] = $staff->trade([$body['tokens'][0]]);

    expect($refused->status)->toBe(401)
        ->and($error)->toBe(['error' => ['code' => 'session_changed']])
        ->and($staff->hub->revoked)->toBe($revoked + 1);
});

it('refuses the panel\'s topics to a former member even where nothing moved the epoch', function () {
    $staff = new Staff(database());
    [, $body] = $staff->trade([$staff->adminToken()]);
    $grants = \count($staff->hub->grants);

    $staff->orm->connection()->execute('DELETE FROM staff WHERE customer_id = ?', [$staff->id]);
    [$renewal, $after] = $staff->trade([$body['tokens'][0]]);

    expect($renewal->status)->toBe(200)
        ->and($after['tokens'])->toBe([null])
        ->and($after['topics'])->toBe([])
        ->and(\count($staff->hub->grants))->toBe($grants);
});

it('moves the epoch when it makes an account staff, too', function () {
    $orm = new Orm(PdoConnection::sqlite(database(), 'shop'), new IdentityMap('shop'));
    $epochs = new AuthEpochs($orm);
    $customer = (string) $orm->connection()->select("SELECT id FROM customers WHERE email <> ? LIMIT 1", [StaffAccount::EMAIL])[0]['id'];
    $before = $epochs->of($customer);

    new StaffMemberships($orm)->grant($customer, [StaffPermission::ViewAllOrders]);

    expect($epochs->of($customer))->not->toBe($before)
        ->and((string) $orm->connection()->select('SELECT permissions FROM staff WHERE customer_id = ?', [$customer])[0]['permissions'])
        ->toBe(StaffPermission::ViewAllOrders->value);
});
