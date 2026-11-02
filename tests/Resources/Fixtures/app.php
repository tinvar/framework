<?php

declare(strict_types=1);

/**
 * An application with one admin resource, through one Kernel (ADR 0152):
 * tickets, each at a site, in the `staff` panel on the admin host, behind
 * a guard, a membership check and the ticket policy.
 */

namespace Tinvar\Tests\Resources\Fixtures;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\AdminResource;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Policy;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Attributes\BelongsTo;
use Tinvar\Orm\Attributes\Column as OrmColumn;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Resources\BulkAction;
use Tinvar\Resources\Column;
use Tinvar\Resources\Data\ResourceDetailData;
use Tinvar\Resources\Data\ResourceSectionData;
use Tinvar\Resources\Field;
use Tinvar\Resources\Filter;
use Tinvar\Resources\Resource;
use Tinvar\Resources\ResourceOperation;
use Tinvar\Resources\ColumnKind;
use Tinvar\Resources\Sort;
use Tinvar\Routing\Domains;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Uuid;

const ADMIN = 'admin.example.test';
const PRIMARY = 'example.test';
const SESSION_COOKIE = '__Host-test_session';

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in-progress';
    case Closed = 'closed';
}

#[Table('sites')]
final class Site extends Model
{
    #[Id] public private(set) string $id;
    #[OrmColumn] public string $name;
}

#[Table('tickets')]
final class Ticket extends Model
{
    #[Id] public private(set) string $id;
    #[OrmColumn] public string $title;
    #[OrmColumn] public TicketStatus $status = TicketStatus::Open;
    #[OrmColumn] public int $points = 1;
    #[OrmColumn] public bool $urgent = false;
    #[OrmColumn] public ?CarbonImmutable $dueAt = null;
    #[OrmColumn] public ?string $notes = null;
    #[OrmColumn] public string $secret = 'kept';
    #[BelongsTo] public Site $site;
}

final readonly class Staff implements Authenticatable
{
    /** @param list<string> $permissions */
    public function __construct(public string $id, public array $permissions = []) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Who is staff, and what each may do, in the panel. */
final class Directory implements Memberships
{
    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        return $user instanceof Staff && $panel === 'staff' ? new Membership('', $user->permissions) : null;
    }
}

/** Signs in whoever the test says, on every request, as a session would: the reset contract signs everyone out after each. */
final class SignedIn implements Middleware
{
    public static ?Authenticatable $user = null;

    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (self::$user !== null) {
            $this->auth->login(self::$user);
        }

        return $next($request);
    }
}

/** Sends a visitor who is not signed in away, as a panel's guard does. */
final class RequireStaff implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->auth->check()) {
            throw new ForbiddenException('Sign in.');
        }

        return $next($request);
    }
}

/**
 * Staff with `tickets.edit` change tickets; anyone in the panel reads
 * them; closed tickets are kept. Each answer can be turned off by a test.
 */
#[Policy(Ticket::class)]
final class TicketPolicy
{
    /** @var array<string, bool> ability => refused, for a test to set */
    public static array $refuse = [];

    public function viewAny(?Authenticatable $user): bool
    {
        return !(self::$refuse['viewAny'] ?? false) && $user instanceof Staff;
    }

    public function view(?Authenticatable $user, Ticket $ticket): bool
    {
        return !(self::$refuse['view'] ?? false) && $user instanceof Staff;
    }

    public function create(?Authenticatable $user): bool
    {
        return $user instanceof Staff && \in_array('tickets.edit', $user->permissions, true);
    }

    public function update(?Authenticatable $user, Ticket $ticket): bool
    {
        return $user instanceof Staff && \in_array('tickets.edit', $user->permissions, true);
    }

    public function delete(?Authenticatable $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket) && $ticket->status !== TicketStatus::Closed;
    }
}

#[AdminResource(model: Ticket::class, panel: 'staff', permission: 'tickets.read', perPage: 2), Domain('admin.{root}')]
final class TicketResource extends Resource
{
    /** @var list<array{string, string}> what written() was told: operation, title */
    public static array $written = [];

    public static function columns(): array
    {
        return [
            Column::make('title')->sortable()->searchable(),
            Column::make('status'),
            Column::make('points')->sortable(),
            Column::make('urgent'),
            Column::make('dueAt', 'Due'),
            Column::make('site'),
        ];
    }

    public static function fields(): array
    {
        return [
            Field::make('title')->max(40),
            Field::make('status'),
            Field::make('points')->min(1)->max(13),
            Field::make('urgent'),
            Field::make('dueAt', 'Due'),
            Field::make('notes')->textarea(),
            Field::make('site'),
        ];
    }

    public static function filters(): array
    {
        return [Filter::make('status'), Filter::make('urgent')];
    }

    public static function bulkActions(): array
    {
        return [BulkAction::delete(), BulkAction::make('escalate', 'Escalate', ability: 'update', method: 'escalate')];
    }

    public static function revalidates(): array
    {
        return ['tickets'];
    }

    public static function sort(): Sort
    {
        return Sort::asc('title');
    }

    protected function escalate(Ticket $ticket): void
    {
        $ticket->urgent = true;
        $ticket->points += 5;
    }

    protected function sections(Model $record): array
    {
        return [new ResourceSectionData('Activity', [new ResourceDetailData('written', 'Changes', ColumnKind::Number, (string) \count(self::$written))])];
    }

    protected function written(Model $record, ResourceOperation $operation): void
    {
        \assert($record instanceof Ticket);
        self::$written[] = [$operation->value, $record->title];
    }
}

/** The application around it: one Kernel, signed in as whoever a test says. */
final class App
{
    public readonly Kernel $kernel;
    public readonly Orm $orm;
    public readonly RouteRegistry $routes;
    public readonly AuthContext $auth;
    public readonly ActionRegistry $actions;

    /** @var list<string> what the Kernel logged */
    public static array $failures = [];

    /** @var array<string, string> ids, by name: site-a, site-b, alpha, beta, gamma */
    public array $ids = [];

    public function __construct()
    {
        self::$failures = [];
        SignedIn::$user = null;
        TicketPolicy::$refuse = [];
        TicketResource::$written = [];

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE sites (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE tickets (id TEXT PRIMARY KEY, title TEXT NOT NULL, status TEXT NOT NULL, points INTEGER NOT NULL, '
            . 'urgent INTEGER NOT NULL, due_at TEXT NULL, notes TEXT NULL, secret TEXT NOT NULL, site_id TEXT NOT NULL)');
        foreach (['site-a' => 'Ashford', 'site-b' => 'Brent'] as $key => $name) {
            $this->ids[$key] = Uuid::v7();
            $pdo->prepare('INSERT INTO sites VALUES (?, ?)')->execute([$this->ids[$key], $name]);
        }
        foreach ([['alpha', 'Alpha', 'open', 3, 0, 'site-a'], ['beta', 'Beta', 'closed', 8, 1, 'site-b'], ['gamma', 'Gamma', 'in-progress', 5, 0, 'site-a']] as [$key, $title, $status, $points, $urgent, $site]) {
            $this->ids[$key] = Uuid::v7();
            $pdo->prepare('INSERT INTO tickets VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?)')
                ->execute([$this->ids[$key], $title, $status, $points, $urgent, 'kept', $this->ids[$site]]);
        }

        $this->orm = new Orm(new PdoConnection($pdo, 'test'), new IdentityMap('test'));
        $container = new Container();
        $container->instance(Orm::class, $this->orm);
        $this->auth = new AuthContext();
        $container->instance(AuthContext::class, $this->auth);
        $this->routes = new RouteRegistry([Site::class, Ticket::class]);
        $this->routes->registerAll([TicketResource::class]);
        $this->actions = new ActionRegistry($container->resolver(), [Site::class, Ticket::class]);
        $this->actions->registerAll([TicketResource::class]);
        $panels = new PanelContext();

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: $this->routes,
            queries: new QueryRegistry($container->resolver()),
            actions: $this->actions,
            auth: $this->auth,
            policies: new PolicyRegistry()->registerAll([Ticket::class => TicketPolicy::class]),
            resolver: $container->resolver(),
            allowedQueries: static fn (Request $r): array => [],
            log: static function (\Throwable $e): void {
                App::$failures[] = $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
            },
            globalMiddleware: [SignedIn::class],
            builds: new BuildRegistry('b1'),
            container: $container,
            panels: $panels,
            panelRegistry: new PanelRegistry(new PanelDefinition('staff', guard: RequireStaff::class, domain: 'admin.{root}')),
            memberships: new Directory(),
            domains: new Domains(['root' => PRIMARY]),
        );
    }

    public function signIn(?Authenticatable $user): self
    {
        SignedIn::$user = $user;

        return $this;
    }

    /** A page on the admin host, answered as JSON: which route, and its data as the encoder would send it. */
    public function get(string $path, string $host = ADMIN): Response
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $parsed);
        $query = array_filter($parsed, static fn (mixed $value, int|string $key): bool => \is_string($key) && \is_string($value), ARRAY_FILTER_USE_BOTH);

        return $this->kernel->handle(
            new Request('GET', (string) parse_url($path, PHP_URL_PATH), ['host' => $host], $query, cookies: [SESSION_COOKIE => 'session-a']),
            static fn (LoadedRoute $loaded, Request $request): Response => Response::make(200, json_encode([
                'route' => $loaded->chain->page->name,
                'view' => $loaded->chain->page->view,
                'data' => $loaded->page === null ? null : json_decode(json_encode(self::plain($loaded->page), JSON_THROW_ON_ERROR), true),
            ], JSON_THROW_ON_ERROR), ['content-type' => 'application/json']),
        );
    }

    /**
     * Calls an action on the admin host from script, with the session's CSRF token -- or with none.
     *
     * @param array<string, mixed> $input
     */
    public function call(string $action, array $input, bool $csrf = true, string $host = ADMIN): Response
    {
        return $this->kernel->handle(
            new Request('POST', "/_action/{$action}", [
                'host' => $host,
                'content-type' => 'application/json',
                'accept' => 'application/json',
                'rsc' => '1',
                ...($csrf ? ['x-xsrf-token' => 'tok'] : []),
            ], cookies: [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => 'tok']),
            static fn (): Response => throw new \LogicException('no page from an action here'),
            json_encode($input, JSON_THROW_ON_ERROR),
        );
    }

    /** A Data tree as arrays, enums as their values and moments as text, as the encoder sends it. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Data => array_map(self::plain(...), get_object_vars($value)),
            \is_array($value) => array_map(self::plain(...), $value),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }
}
