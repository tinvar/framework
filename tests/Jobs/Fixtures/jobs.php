<?php

declare(strict_types=1);

namespace Tinvar\Tests\Jobs\Fixtures;

use Tinvar\Container\Dependency;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Queue;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Container\Lifetime;
use Tinvar\Http\RequestScope;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\Envelope;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobRunner;
use Tinvar\Jobs\Queueable;
use Tinvar\Jobs\Refusal;
use Tinvar\Auth\UserLookup;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\Queue as JobQueue;
use Tinvar\Jobs\Worker;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

const KEY = 'jobs-test-key-of-thirty-two-bytes!!';

/** What jobs saw when they ran, kept for the worker's life as a real service would be. */
#[Dependency(Lifetime::Worker)]
final class Journal
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];
}

/** A request-lived service, which the reset contract must drop between jobs. */
#[Dependency(Lifetime::Request)]
final class PerJob
{
    public string $id;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(4));
    }
}

final class User implements Authenticatable
{
    public function __construct(public readonly string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Users by id, each on an epoch a test can move. */
final class Users implements UserLookup
{
    /** @var array<string, string> id => epoch */
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

/** Staff hold the admin panel in any tenant; nobody else holds anything. */
final class StaffOnly implements Memberships
{
    /** @var list<string> */
    public array $staff = [];

    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        return $panel === 'admin' && \in_array($user->authIdentifier(), $this->staff, true) ? new Membership('', ['orders.view']) : null;
    }
}

/** Records the context it ran in, and the script it was dispatched with. */
final readonly class RecordContext implements Job
{
    public function __construct(public ?Script $script = null, public string $note = '') {}

    public function handle(Journal $journal, Orm $orm, AuthContext $auth, PanelContext $panels, PerJob $perJob): void
    {
        $journal->entries[] = [
            'note' => $this->note,
            'tenant' => $orm->tenant(),
            'user' => $auth->id(),
            'panel' => $panels->currentOrNull(),
            'script' => $this->script?->name,
            'perJob' => $perJob->id,
            'mapped' => $orm->identityMap()->count(),
            'subtree' => $orm->isSubtreeGranted(),
            'granted' => $panels->granted()?->name,
        ];
    }
}

/** Fails every try, and says so when its last one has. */
#[Queue(tries: 3, backoff: [5, 30])]
final readonly class AlwaysFails implements Job
{
    public function __construct(public string $note = '') {}

    public function handle(Journal $journal): void
    {
        $journal->entries[] = ['tried' => $this->note];

        throw new \RuntimeException("failing on purpose: {$this->note}");
    }

    public function failed(\Throwable $error): void
    {
        Hooks::$failed[] = $error->getMessage();
    }
}

/** Fails once and for good, its failed() taking the errors it expects by a union. */
#[Queue(tries: 1)]
final readonly class FailsIntoUnion implements Job
{
    public function __construct(public bool $logic = false) {}

    public function handle(): void
    {
        throw $this->logic ? new \DomainException('a domain error') : new \RuntimeException('a runtime error');
    }

    public function failed(\LengthException|\RuntimeException $error): void
    {
        Hooks::$failed[] = $error->getMessage();
    }
}

final class Hooks
{
    /** @var list<string> */
    public static array $failed = [];
}

/** Fails once, then works. */
#[Queue(tries: 2, backoff: [0])]
final readonly class FailsOnce implements Job
{
    public function handle(Journal $journal): void
    {
        $journal->entries[] = ['flaky' => true];
        if (\count($journal->entries) === 1) {
            throw new \RuntimeException('first try fails');
        }
    }
}

/** Runs past its one-second timeout. */
#[Queue(tries: 1, timeout: 1)]
final readonly class Sleeps implements Job
{
    public function __construct(public bool $swallow = false) {}

    public function handle(Journal $journal): void
    {
        $until = microtime(true) + 3;
        while (microtime(true) < $until) {
            try {
                usleep(20_000);
            } catch (\Tinvar\Jobs\JobTimedOut $e) {
                if (!$this->swallow) {
                    throw $e;
                }
                $journal->entries[] = ['swallowed' => true];
            }
        }
        $journal->entries[] = ['finished' => true];
    }
}

#[Queue(asUser: true)]
final readonly class AsDispatcher implements Job
{
    public function handle(Journal $journal, AuthContext $auth, PanelContext $panels): void
    {
        $journal->entries[] = ['user' => $auth->id(), 'epoch' => $auth->epoch(), 'panel' => $panels->currentOrNull()];
    }
}

/** Runs as its dispatcher, and says where it was refused from (ADR 0107). */
#[Queue(asUser: true)]
final readonly class TellsRefusal implements Job
{
    public function __construct(public string $note = '') {}

    public function handle(Journal $journal): void
    {
        $journal->entries[] = ['ran' => $this->note];
    }

    public function failed(\Throwable $error): void
    {
        Hooks::$failed[] = 'failed: ' . $this->note;
    }

    public function refused(Refusal $refusal, Journal $journal, Orm $orm, AuthContext $auth, PanelContext $panels): void
    {
        $journal->entries[] = [
            'refused' => $this->note,
            'reason' => $refusal->reason,
            'sealed' => [$refusal->tenant, $refusal->panel, $refusal->user],
            'running as' => [$orm->tenant(), $auth->id(), $panels->currentOrNull()],
        ];
    }
}

#[Panel('admin')]
final readonly class AdminWork implements Job
{
    public function handle(Journal $journal, PanelContext $panels): void
    {
        $journal->entries[] = ['panel' => $panels->current()];
    }
}

/** Leaves a mess: another tenant, a user signed in, rows in the map, a transaction open. */
final readonly class LeavesState implements Job
{
    public function handle(Orm $orm, AuthContext $auth, Journal $journal): void
    {
        $orm->switchTenant('globex');
        $orm->all(Script::class);
        $auth->login(new User('intruder'));
        $connection = $orm->connection();
        \assert($connection instanceof \Tinvar\Orm\PdoConnection);
        $connection->pdo()->beginTransaction();
        $connection->execute("INSERT INTO scripts VALUES ('leak', 'leak', 'globex')");
        $journal->entries[] = ['left' => 'state'];
    }
}

/** A value object that travels inside a job. */
final readonly class Address implements Queueable
{
    public function __construct(public string $line, public Colour $colour) {}
}

enum Colour: string
{
    case Red = 'red';
    case Blue = 'blue';
}

/** Carries a CarbonImmutable moment, as a model's timestamps are typed (ADR 0145). */
final readonly class CarriesMoment implements Job
{
    public function __construct(
        public \Carbon\CarbonImmutable $at,
        public ?\Carbon\CarbonImmutable $until = null,
    ) {}

    public function handle(Journal $journal): void
    {
        $journal->entries[] = ['job' => $this];
    }
}

/** Carries every kind of value a payload can. */
final readonly class CarriesValues implements Job
{
    /** @param array<array-key, mixed> $list */
    public function __construct(
        public array $list,
        public \DateTimeImmutable $at,
        public Colour $colour,
        public Address $address,
        public ?float $ratio = null,
    ) {}

    public function handle(Journal $journal): void
    {
        $journal->entries[] = ['job' => $this];
    }
}

/** Takes something that cannot travel. */
final readonly class CarriesClosure implements Job
{
    public function __construct(public \Closure $callback) {}

    public function handle(): void {}
}

/**
 * Everything a job test needs, over the two-tenant scripts fixture: a queue
 * in memory, a dispatcher and a runner sharing one Orm, auth and panel
 * context, and a worker resetting them between jobs.
 */
final class Harness
{
    public TenantFixture $fixture;
    public Orm $orm;
    public AuthContext $auth;
    public PanelContext $panels;
    public PanelRegistry $registry;
    public Container $container;
    public JobQueue $queue;
    public Dispatcher $dispatcher;
    public JobRunner $runner;
    public RequestScope $scope;
    public Worker $worker;
    public Signer $signer;
    public Users $users;
    public StaffOnly $memberships;
    public int $now = 1_800_000_000;

    /** @var list<string> */
    public array $log = [];

    /** @var list<int> */
    public array $exits = [];

    public function __construct(?JobQueue $queue = null, ?TenantFixture $fixture = null)
    {
        $this->fixture = $fixture ?? new TenantFixture();
        $this->orm = new Orm($this->fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());
        $this->auth = new AuthContext();
        $this->panels = new PanelContext();
        $this->registry = new PanelRegistry(new PanelDefinition('admin'), new PanelDefinition('reseller'));
        $this->users = new Users();
        $this->memberships = new StaffOnly();
        $this->container = new Container()
            ->instance(Orm::class, $this->orm)
            ->instance(AuthContext::class, $this->auth)
            ->instance(PanelContext::class, $this->panels);
        $this->signer = new Signer(KEY, Envelope::PURPOSE);
        $this->queue = $queue ?? new ArrayQueue();
        $clock = fn (): int => $this->now;

        $this->dispatcher = new Dispatcher($this->queue, $this->orm, $this->signer, $this->auth, $this->panels, $this->registry, $clock);
        $this->runner = new JobRunner(
            $this->orm,
            $this->signer,
            $this->container->resolver(),
            $this->auth,
            $this->panels,
            $this->registry,
            $this->memberships,
            $this->users,
        );
        $this->scope = RequestScope::standard($this->orm, $this->auth, new PolicyRegistry(), $this->panels)
            ->add('container', $this->container->endRequest(...));
        $this->worker = new Worker(
            $this->queue,
            $this->runner,
            $this->scope,
            log: function (string $line): void {
                $this->log[] = $line;
            },
            clock: $clock,
            exit: function (int $status): void {
                $this->exits[] = $status;
            },
        );
    }

    public function journal(): Journal
    {
        return $this->container->get(Journal::class);
    }

    /** Works every job available now, and returns how many ran. */
    public function work(): int
    {
        return $this->worker->run(new \Tinvar\Jobs\WorkerOptions(stopWhenEmpty: true, signals: false));
    }
}

/** The schema builder over $connection, as a migration is handed it. */
function schemaOf(\Tinvar\Orm\Connection $connection): \Tinvar\Orm\Schema\Schema
{
    return new \Tinvar\Orm\Schema\Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection));
}

/** A database queue on $connection, its tables made as the application's migration makes them. */
function databaseQueue(\Tinvar\Orm\Connection $connection): \Tinvar\Queue\DatabaseQueue
{
    \Tinvar\Queue\DatabaseQueue::define(schemaOf($connection));

    return new \Tinvar\Queue\DatabaseQueue($connection);
}
