<?php

declare(strict_types=1);

/**
 * Events and their #[Listener] methods: run now, after the commit, or on a
 * worker -- and refused, like a write, inside a loader or query body.
 */

namespace Tinvar\Tests\Events\Events;

use Tinvar\Attributes\Listener;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Queue;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelContext;
use Tinvar\Events\EventDispatcher;
use Tinvar\Events\EventFake;
use Tinvar\Events\EventRegistry;
use Tinvar\Jobs\Queueable;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Jobs\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

interface Announced {}

final readonly class OrderPlaced implements Queueable, Announced
{
    public function __construct(public string $number) {}
}

final readonly class NotQueueable
{
    public function __construct(public string $number) {}
}

final class Listeners
{
    public function __construct(private readonly Journal $journal) {}

    #[Listener]
    public function now(OrderPlaced $event): void
    {
        $this->journal->entries[] = ['now' => $event->number];
    }

    #[Listener(afterCommit: true)]
    public function later(OrderPlaced $event): void
    {
        $this->journal->entries[] = ['afterCommit' => $event->number];
    }

    #[Listener]
    public function anything(Announced $event): void
    {
        $this->journal->entries[] = ['announced' => $event::class];
    }

    public function notAListener(OrderPlaced $event): void
    {
        $this->journal->entries[] = ['wrong' => true];
    }
}

#[Queue('events', tries: 2), Panel('admin')]
final class QueuedListeners
{
    public function __construct(private readonly Journal $journal, private readonly Orm $orm, private readonly PanelContext $panels) {}

    #[Listener]
    public function onWorker(OrderPlaced $event): void
    {
        $this->journal->entries[] = ['queued' => $event->number, 'tenant' => $this->orm->tenant(), 'panel' => $this->panels->currentOrNull()];
    }
}

final class TenantListeners
{
    public function __construct(private readonly Journal $journal, private readonly Orm $orm) {}

    #[Listener(afterCommit: true)]
    public function later(OrderPlaced $event): void
    {
        $this->journal->entries[] = ['afterCommit' => $event->number, 'tenant' => $this->orm->tenant()];
    }
}

final class ScopeListeners
{
    public function __construct(
        private readonly Journal $journal,
        private readonly Orm $orm,
        private readonly AuthContext $auth,
        private readonly PanelContext $panels,
    ) {}

    #[Listener(afterCommit: true)]
    public function later(OrderPlaced $event): void
    {
        $this->journal->entries[] = [
            'afterCommit' => $event->number,
            'tenant' => $this->orm->tenant(),
            'unscoped' => $this->orm->isUnscoped(),
            'subtree' => $this->orm->subtreeInForce() !== null,
            'user' => $this->auth->id(),
            'epoch' => $this->auth->epoch(),
            'panel' => $this->panels->currentOrNull(),
            'granted' => $this->panels->granted()?->name,
            'grantedSubtree' => $this->panels->granted()?->subtree,
            'ormGranted' => $this->orm->isSubtreeGranted(),
        ];
    }
}

#[Queue]
final class QueuedWithBadEvent
{
    #[Listener]
    public function on(NotQueueable $event): void {}
}

final class NoListeners
{
    public function handle(OrderPlaced $event): void {}
}

function events(Harness $h, array $classes): EventDispatcher
{
    $registry = new EventRegistry();
    $registry->registerAll($classes);

    return new EventDispatcher($registry, $h->orm, $h->container->resolver(), $h->dispatcher, $h->auth, $h->panels);
}

it('runs listeners now, by the event\'s type and the interfaces it implements', function () {
    $h = new Harness();
    events($h, [Listeners::class])->dispatch(new OrderPlaced('PW-1'));

    expect($h->journal()->entries)->toBe([
        ['now' => 'PW-1'],
        ['afterCommit' => 'PW-1'],
        ['announced' => OrderPlaced::class],
    ]);
});

it('holds an afterCommit listener until the commit, and drops it on a rollback', function () {
    $h = new Harness();
    $events = events($h, [Listeners::class]);

    $h->orm->transaction(function () use ($h, $events): void {
        $events->dispatch(new OrderPlaced('PW-2'));
        expect(array_column($h->journal()->entries, 'afterCommit'))->toBe([]);
    });
    try {
        $h->orm->transaction(function () use ($events): void {
            $events->dispatch(new OrderPlaced('PW-3'));

            throw new \RuntimeException('roll back');
        });
    } catch (\RuntimeException) {
    }

    expect(array_column($h->journal()->entries, 'afterCommit'))->toBe(['PW-2'])
        ->and(array_column($h->journal()->entries, 'now'))->toBe(['PW-2', 'PW-3']);
});

// The commit can come in another tenant than the dispatch: a transaction
// opened as one tenant, the event dispatched inside actAs() for another --
// a task's forEachTenant() in one transaction, a reseller's action acting
// as each customer. The listener is about the dispatching tenant's event,
// and ran as whichever tenant the commit happened in.
it('runs an afterCommit listener as the tenant it was dispatched in, wherever the commit comes', function () {
    $h = new Harness();
    $events = events($h, [TenantListeners::class]);

    $h->orm->actAs('globex', fn () => $h->orm->transaction(
        fn () => $h->orm->actAs('acme', fn () => $events->dispatch(new OrderPlaced('PW-7'))),
    ));
    $h->orm->transaction(fn () => $h->orm->actAs('acme', fn () => $events->dispatch(new OrderPlaced('PW-8'))));
    $h->orm->actAs('acme', fn () => $events->dispatch(new OrderPlaced('PW-9')));

    expect($h->journal()->entries)->toBe([
        ['afterCommit' => 'PW-7', 'tenant' => 'acme'],
        ['afterCommit' => 'PW-8', 'tenant' => 'acme'],
        ['afterCommit' => 'PW-9', 'tenant' => 'acme'],
    ])->and($h->orm->tenant())->toBe('');
});

// The same tenant name is not the same scope: a commit inside acrossTenants()
// or withinSubtree(), of a transaction opened there, ran the listener with
// tenant scoping lifted or widened, because only the tenant name was kept.
it('runs an afterCommit listener scoped to its tenant when the commit comes inside acrossTenants() or withinSubtree()', function () {
    $h = new Harness();
    $orm = new Orm($h->fixture->connection, new IdentityMap('events-tree'), tenants: Tenancy::tree(['globex' => 'acme']));
    $h->container->instance(Orm::class, $orm);
    $registry = new EventRegistry();
    $registry->register(ScopeListeners::class);
    $events = new EventDispatcher($registry, $orm, $h->container->resolver(), $h->dispatcher, $h->auth, $h->panels);

    $orm->actAs('acme', fn () => $orm->acrossTenants(fn () => $orm->transaction(
        fn () => $orm->actAs('acme', fn () => $events->dispatch(new OrderPlaced('PW-10'))),
    )));
    $orm->actAs('acme', fn () => $orm->grantSubtree(fn () => $orm->withinSubtree(fn () => $orm->transaction(
        fn () => $events->dispatch(new OrderPlaced('PW-11')),
    ))));

    expect(array_map(static fn (array $e): array => [$e['tenant'], $e['unscoped'], $e['subtree']], $h->journal()->entries))
        ->toBe([['acme', false, false], ['acme', false, false]]);
});

// And the user and panel: the listener is about the dispatcher's event, so it
// runs as the user, on the epoch and in the panel the event was dispatched
// in -- not as whoever the code around the commit was acting as.
it('runs an afterCommit listener as the user, epoch and panel it was dispatched in', function () {
    $h = new Harness();
    $events = events($h, [ScopeListeners::class]);

    $h->auth->actingAs(new User('alice'), fn () => $h->panels->within('admin', fn () => $h->orm->transaction(
        fn () => $h->auth->actingAs(
            new User('bob'),
            fn () => $h->panels->within('reseller', fn () => $events->dispatch(new OrderPlaced('PW-12'))),
            'e2',
        ),
    )), 'e1');

    expect($h->journal()->entries[0])->toMatchArray(['user' => 'bob', 'epoch' => 'e2', 'panel' => 'reseller']);
});

// The membership check that let the dispatch into its panel comes back with
// it, and never its subtree: the listener runs with the ORM's subtree grant
// lifted, and a binding still saying `subtree` would key the query cache's
// entries and tags as the subtree's while the ORM read the tenant alone.
it('restores an afterCommit listener\'s panel grant without its subtree, as the ORM\'s grant is lifted', function () {
    $h = new Harness();
    $events = events($h, [ScopeListeners::class]);
    $binding = new PanelBinding('admin', permissions: ['orders.view'], subtree: true);

    $h->orm->actAs('acme', fn () => $h->orm->transaction(fn () => $h->panels->within(
        'admin',
        fn () => $h->panels->grantedWithin($binding, fn () => $h->orm->grantSubtree(fn () => $events->dispatch(new OrderPlaced('PW-14')))),
    )));
    $h->orm->actAs('acme', fn () => $h->panels->within('admin', fn () => $h->panels->grantedWithin($binding, fn () => $h->orm->transaction(
        fn () => $h->auth->actingAs(new User('bob'), fn () => $events->dispatch(new OrderPlaced('PW-15')), 'e2'),
    ))));

    expect(array_map(static fn (array $e): array => [$e['granted'], $e['grantedSubtree'], $e['ormGranted']], $h->journal()->entries))
        ->toBe([['admin', false, false], ['admin', false, false]]);
});

it('queues a listener whose class has #[Queue], under that class\'s queue, tries and panel', function () {
    $h = new Harness();
    $h->orm->actAs('acme', fn () => events($h, [QueuedListeners::class])->dispatch(new OrderPlaced('PW-4')));

    \assert($h->queue instanceof ArrayQueue);
    expect($h->journal()->entries)->toBe([])
        ->and($h->queue->size('events'))->toBe(1);

    $h->worker->run(new \Tinvar\Jobs\WorkerOptions(queues: ['events'], stopWhenEmpty: true, signals: false));

    expect($h->journal()->entries)->toBe([['queued' => 'PW-4', 'tenant' => 'acme', 'panel' => 'admin']]);
});

// Review: a listener class a deploy removed threw a ReflectionException,
// and a method whose event a deploy retyped a TypeError -- each retried as
// if the next try could differ.
it('refuses a queued call to a listener class that is gone, or a method that no longer takes the event', function () {
    $h = new Harness();
    $call = static fn (string $class, string $method): \Closure => static fn () => new \Tinvar\Events\CallQueuedListener($class, $method, new OrderPlaced('PW-5'))->handle($h->container);

    expect($call('Tinvar\\Tests\\Events\\Events\\RemovedListeners', 'onWorker'))->toThrow(\Tinvar\Jobs\JobRefused::class)
        ->and($call(QueuedWithBadEvent::class, 'on'))->toThrow(\Tinvar\Jobs\JobRefused::class);
});

it('refuses event dispatch inside a read-only scope, before any listener runs', function () {
    $h = new Harness();
    $events = events($h, [Listeners::class]);

    expect(fn () => $h->orm->readOnly(fn () => $events->dispatch(new OrderPlaced('PW-5'))))
        ->toThrow(ReadOnlyViolation::class, 'Event dispatch is not allowed inside a loader or query body')
        ->and($h->journal()->entries)->toBe([])
        ->and(fn () => $h->orm->readOnly(fn () => new EventFake($h->orm)->dispatch(new OrderPlaced('PW-5'))))
        ->toThrow(ReadOnlyViolation::class);
});

it('refuses at registration a queued listener whose event cannot travel, and a class with no listener', function () {
    expect(fn () => new EventRegistry()->register(QueuedWithBadEvent::class))
        ->toThrow(\InvalidArgumentException::class, 'is not Queueable')
        ->and(fn () => new EventRegistry()->register(NoListeners::class))
        ->toThrow(\InvalidArgumentException::class, 'no #[Listener] method');
});

it('records events on the fake instead of running listeners', function () {
    $fake = new EventFake();
    $fake->dispatch(new OrderPlaced('PW-6'));
    $fake->dispatch(new NotQueueable('x'));

    expect($fake->dispatched(OrderPlaced::class))->toEqual([new OrderPlaced('PW-6')])
        ->and($fake->dispatched(Announced::class))->toHaveCount(1);
});
