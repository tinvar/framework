<?php

declare(strict_types=1);

/**
 * A small application with exports and imports, for the transfer tests:
 * widgets in two tenants, each with a secret no file may carry; an export
 * of them and an import of their stock, in a `staff` panel; a Kernel wired
 * with Transfers over memory, a queue the test works by hand as a worker
 * would, a clock the test moves, and users whose auth epoch the test bumps.
 */

namespace Tinvar\Tests\Transfers\Fixtures;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Export;
use Tinvar\Attributes\Import;
use Tinvar\Attributes\Panel;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Cache\ArrayTagVersions;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\UploadedFile;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\Envelope;
use Tinvar\Jobs\JobRefused;
use Tinvar\Jobs\JobRunner;
use Tinvar\Auth\UserLookup;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Query;
use Tinvar\Query\QueryRegistry;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Transfers\ArrayTransferStore;
use Tinvar\Transfers\TransferNotifier;
use Tinvar\Transfers\TransferRecord;
use Tinvar\Transfers\TransferRegistry;
use Tinvar\Transfers\Transfers;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Min;

require_once __DIR__ . '/../../../../database/tests/Orm/Fixtures/tenancy.php';

const KEY = 'transfer-test-application-key-0123456789';
const SESSION_COOKIE = '__Host-test_session';
const CSRF = 'csrf-token-for-the-tests';

const W1 = '0192f3a1-0000-7000-8000-000000000001';
const W2 = '0192f3a1-0000-7000-8000-000000000002';
const W3 = '0192f3a1-0000-7000-8000-000000000003';
/** Globex's: never acme's to read, export or restock. */
const G1 = '0192f3a1-0000-7000-8000-0000000000a9';

final readonly class User implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

#[Table('widgets'), TenantScoped]
final class Widget extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $sku;
    #[Column] public string $name;
    #[Column] public int $stock;

    /** On no row class: what no export may carry. */
    #[Column] public string $secret;
}

/** Staff may export and restock; a locked widget may not be restocked by anyone. */
final class WidgetPolicy
{
    public function export(?Authenticatable $user, string $class): bool
    {
        return $user !== null && str_starts_with($user->authIdentifier(), 'staff');
    }

    public function restock(?Authenticatable $user, string $class): bool
    {
        return $this->export($user, $class);
    }

    public function update(?Authenticatable $user, Widget $widget): bool
    {
        return $user !== null && $widget->sku !== 'LOCKED';
    }
}

final readonly class WidgetRow extends Data
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $stock,
    ) {}
}

#[Export(WidgetRow::class, name: 'widgets', maxRows: 1_000), Panel('staff'), Authorize('export', Widget::class)]
final readonly class WidgetsExport
{
    public function __construct(private Orm $orm) {}

    public function __invoke(?int $minStock = null): Query
    {
        $query = Widget::query($this->orm)->orderBy('sku');

        return $minStock === null ? $query : $query->where('stock', '>=', $minStock);
    }
}

final readonly class StockRow extends FormRequest
{
    public function __construct(
        public Widget $widget,
        #[Min(0)]
        public int $stock,
    ) {}
}

#[Import(name: 'stock', maxBytes: 4096, maxRows: 5, revalidates: ['widgets']), Panel('staff'),
    Authorize('restock', Widget::class), Authorize('update', 'widget'),]
final readonly class StockImport
{
    public function __construct(private Orm $orm) {}

    public function __invoke(StockRow $row): void
    {
        $row->widget->stock = $row->stock;
        $this->orm->save($row->widget);
    }
}

/** The application's users, by id, at the epoch the test has them on. */
final class Users implements UserLookup
{
    /** @var array<string, string> */
    public array $epochs = [];

    public function find(string $id): ?Authenticatable
    {
        return isset($this->epochs[$id]) ? new User($id) : null;
    }

    public function epoch(Authenticatable $user): string
    {
        return $this->epochs[$user->authIdentifier()] ?? '';
    }
}

/** What the application was told, and the links it was given. */
final class Notes implements TransferNotifier
{
    /** @var list<array{record: TransferRecord, user: string, link: ?string}> */
    public array $finished = [];

    public function finished(TransferRecord $record, Authenticatable $user, ?string $link): void
    {
        $this->finished[] = ['record' => $record, 'user' => $user->authIdentifier(), 'link' => $link];
    }
}

/**
 * Signs in whoever the test says, at the epoch it says, and enters the
 * tenant it says -- as an application's session middleware would.
 */
final class SignIn implements Middleware
{
    public static ?AuthContext $auth = null;
    public static ?Orm $orm = null;

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->header('x-test-user');
        self::$auth?->login($user === null ? null : new User($user), $request->header('x-test-epoch') ?? '');
        self::$orm?->switchTenant($request->header('x-test-tenant') ?? 'acme');

        return $next($request);
    }
}

/** The application, and what a test needs to reach into it. */
final class App
{
    public int $now = 1_800_000_000;

    public readonly Kernel $kernel;
    public readonly Orm $orm;
    public readonly AuthContext $auth;
    public readonly Transfers $transfers;
    public readonly ArrayTransferStore $store;
    public readonly MemoryStorage $storage;
    public readonly ArrayQueue $queue;
    public readonly JobRunner $runner;
    public readonly Users $users;
    public readonly Notes $notes;
    public readonly PdoConnection $connection;

    /** The versions once-shared holds are bound to, which an import's tags move. */
    public readonly ArrayTagVersions $versions;

    public function __construct(?string $origin = 'https://shop.example')
    {
        $this->connection = PdoConnection::sqlite(':memory:', 'test');
        $this->connection->execute('CREATE TABLE widgets (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, sku TEXT NOT NULL, name TEXT NOT NULL, stock INTEGER NOT NULL, secret TEXT NOT NULL)');
        $this->connection->execute(
            'INSERT INTO widgets (id, tenant_id, sku, name, stock, secret) VALUES (?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?)',
            [
                W1, 'acme', 'A-1', 'Anvil', 3, 'acme-secret-1',
                W2, 'acme', 'B-2', '=HYPERLINK("http://evil.example","click")', 5, 'acme-secret-2',
                W3, 'acme', 'LOCKED', '-Locked', 7, 'acme-secret-3',
                G1, 'globex', 'G-1', 'Globex gear', 11, 'globex-secret',
            ],
        );

        $this->orm = new Orm($this->connection, new IdentityMap('test'), tenants: Tenancy::flat());
        $this->auth = new AuthContext();
        SignIn::$auth = $this->auth;
        SignIn::$orm = $this->orm;

        $container = new Container();
        $container->instance(Orm::class, $this->orm);

        $panels = new PanelContext();
        $registry = new PanelRegistry(new PanelDefinition('staff'));
        $this->users = new Users();
        $this->users->epochs = ['staff-1' => 'e1', 'staff-2' => 'e1', 'customer-1' => 'e1'];
        $this->notes = new Notes();

        $jobSigner = new Signer(KEY, Envelope::PURPOSE);
        $this->queue = new ArrayQueue();
        $dispatcher = new Dispatcher($this->queue, $this->orm, $jobSigner, $this->auth, $panels, $registry, fn (): int => $this->now);
        $this->runner = new JobRunner($this->orm, $jobSigner, $container->resolver(), $this->auth, $panels, $registry, users: $this->users);

        $transfers = new TransferRegistry();
        $transfers->registerAll([WidgetsExport::class, StockImport::class]);
        $this->store = new ArrayTransferStore();
        $this->storage = new MemoryStorage();
        $this->transfers = Transfers::fromKey(
            KEY,
            $transfers,
            $this->store,
            $this->storage,
            $dispatcher->dispatch(...),
            $this->notes,
            $origin === null ? null : static fn (Authenticatable $user, string $tenant): string => $origin,
            clock: fn (): int => $this->now,
        );

        $this->versions = new ArrayTagVersions();
        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: new RouteRegistry(),
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: $this->auth,
            policies: (new PolicyRegistry())->registerAll([Widget::class => WidgetPolicy::class]),
            resolver: $container->resolver(),
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [SignIn::class],
            panels: $panels,
            container: $container,
            panelRegistry: $registry,
            transfers: $this->transfers,
            tagVersions: $this->versions,
        );
    }

    /**
     * Starts the export, as a page's button does.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers over the signed-in, CSRF-carrying defaults
     */
    public function export(array $body = [], string $user = 'staff-1', string $epoch = 'e1', array $headers = [], string $name = 'widgets'): Response
    {
        return $this->kernel->handle(
            new Request(
                'POST',
                "/_export/{$name}",
                [
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                    'x-xsrf-token' => CSRF,
                    'x-test-user' => $user,
                    'x-test-epoch' => $epoch,
                    ...$headers,
                ],
                cookies: [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => CSRF],
            ),
            self::noPage(...),
            json_encode((object) $body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Starts the import with a file, as the import form does.
     *
     * @param array<string, string> $headers
     */
    public function import(string $csv, string $user = 'staff-1', array $headers = [], int $error = UPLOAD_ERR_OK, string $name = 'stock'): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'tinvar-import-');
        \assert(\is_string($path));
        file_put_contents($path, $csv);

        try {
            return $this->kernel->handle(
                new Request(
                    'POST',
                    "/_import/{$name}",
                    [
                        'content-type' => 'multipart/form-data; boundary=----tinvar',
                        'accept' => 'application/json',
                        'x-xsrf-token' => CSRF,
                        'x-test-user' => $user,
                        'x-test-epoch' => 'e1',
                        ...$headers,
                    ],
                    cookies: [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => CSRF],
                    files: ['file' => new UploadedFile($error === UPLOAD_ERR_OK ? $path : '', 'stock.csv', 'text/csv', $error, \strlen($csv))],
                ),
                self::noPage(...),
            );
        } finally {
            @unlink($path);
        }
    }

    /** GETs a /_download path, as the browser following the link does. */
    public function download(string $path, string $user = 'staff-1', string $epoch = 'e1', string $tenant = 'acme'): Response
    {
        return $this->kernel->handle(
            new Request('GET', $path, ['x-test-user' => $user, 'x-test-epoch' => $epoch, 'x-test-tenant' => $tenant], cookies: [SESSION_COOKIE => 'session-b']),
            self::noPage(...),
        );
    }

    /**
     * Runs every queued job as a worker does, outside any request.
     *
     * @return list<string> each refused job's reason
     */
    public function work(): array
    {
        $refused = [];
        while (($job = $this->queue->reserve([Transfers::QUEUE], $this->now, 60)) !== null) {
            try {
                $this->runner->run($this->runner->open($job->payload));
            } catch (JobRefused $e) {
                $refused[] = $e->reason;
            }
            $this->queue->delete($job);
            $this->orm->identityMap()->flush();
        }

        return $refused;
    }

    /** The one record there is. */
    public function record(): TransferRecord
    {
        $all = $this->store->all();
        \assert(\count($all) === 1);

        return $all[0];
    }

    /** The download path a page shows the viewer, minted as TransferStatus mints it. */
    public function linkFor(TransferRecord $record, string $user = 'staff-1', string $epoch = 'e1'): string
    {
        return $this->transfers->downloadPath($record, $user, $epoch);
    }

    public function stockOf(string $id, string $tenant = 'acme'): int
    {
        return $this->orm->actAs($tenant, function () use ($id): int {
            $this->orm->identityMap()->flush();
            $widget = $this->orm->find(Widget::class, $id);
            \assert($widget instanceof Widget);

            return $widget->stock;
        });
    }

    private static function noPage(LoadedRoute $loaded, Request $request): Response
    {
        return Response::make(500, 'No page renders in these tests.');
    }
}

/** @return array<string, mixed> */
function json(Response $response): array
{
    $decoded = json_decode($response->body(), true);

    return \is_array($decoded) ? $decoded : [];
}
