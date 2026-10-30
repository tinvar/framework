<?php

declare(strict_types=1);

/**
 * A workflow's signals, its waits on them and its progress record (ADR
 * 0119): a wait is a step a signal or a deadline finishes, looking again
 * without spending the workflow's pauses, woken at once by a signal; the
 * record says how far it has got and how it ended; both are held to the
 * tenant and panel it was started in.
 */

namespace Tinvar\Tests\Workflows\SignalsAndProgress;

use Tinvar\Jobs\SyncQueue;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\User;
use Tinvar\Tests\Workflows\Fixtures\AwaitsCollection;
use Tinvar\Tests\Workflows\Fixtures\Flaky;
use Tinvar\Tests\Workflows\Fixtures\Seen;
use Tinvar\Tests\Workflows\Fixtures\ThreeSteps;
use Tinvar\Workflows\WorkflowRecord;
use Tinvar\Workflows\Workflows;

use function Tinvar\Tests\Workflows\Fixtures\harness;

require_once __DIR__ . '/Fixtures/workflows.php';

function queue(Harness $h): ArrayQueue
{
    \assert($h->queue instanceof ArrayQueue);

    return $h->queue;
}

function workflows(Harness $h): Workflows
{
    $workflows = $h->container->get(Workflows::class);
    \assert($workflows instanceof Workflows);

    return $workflows;
}

/** Starts the workflow as staff member u1 in the admin panel, as the shop's staff start a fulfilment. */
function startAsStaff(Harness $h, \Tinvar\Workflows\Workflow $workflow): string
{
    $h->users->epochs['u1'] = 'e1';

    return $h->auth->actingAs(new User('u1'), fn (): string => $h->panels->within('admin', fn (): string => workflows($h)->start($workflow)), 'e1');
}

it('waits for a signal without holding a worker, wakes when it comes, and hands the step what it said', function () {
    $h = harness();
    $id = workflows($h)->start(new AwaitsCollection());
    expect(workflows($h)->progress($id)?->status)->toBe(WorkflowRecord::QUEUED);

    // Packed, then waiting: back on the queue to look again in ten minutes.
    $h->work();
    $record = workflows($h)->progress($id);
    expect($record?->status)->toBe(WorkflowRecord::WAITING)
        ->and($record?->step)->toBe('collected')
        ->and($record?->steps)->toBe(1)
        ->and($record?->wakeAt)->toBe($h->now + 600)
        ->and(queue($h)->availableAt($id))->toBe($h->now + 600);

    // The warehouse says so: kept once, and the workflow woken at once.
    $h->now += 5;
    expect(workflows($h)->signal($id, 'collected', ['by' => 'warehouse', 'at' => 1234]))->toBeTrue()
        ->and(workflows($h)->signal($id, 'collected', ['by' => 'someone else']))->toBeFalse('a name is signalled once')
        ->and(queue($h)->availableAt($id))->toBe($h->now);

    $h->work();
    expect(array_column(Seen::$steps, 'step'))->toBe(['pack', 'ship'])
        ->and(Seen::$steps[1]['collected'])->toBe(['by' => 'warehouse', 'at' => 1234])
        ->and(workflows($h)->progress($id)?->status)->toBe(WorkflowRecord::FINISHED)
        ->and(workflows($h)->progress($id)?->finishedAt)->toBe($h->now)
        ->and(workflows($h)->signal($id, 'late', []))->toBeFalse('an ended workflow takes no signal');
});

it('looks again until its deadline without spending its pauses, and goes on with null when none came', function () {
    $h = harness();
    $id = workflows($h)->start(new AwaitsCollection());
    $h->work();

    // Six looks, ten minutes apart: twice the three pauses it declares,
    // none of them counted. The deadline, sealed at the first look, holds.
    for ($look = 0; $look < 6; $look++) {
        $h->now += 600;
        $h->work();
    }

    expect(array_column(Seen::$steps, 'step'))->toBe(['pack', 'flag'])
        ->and(Seen::$steps[1]['collected'])->toBeNull()
        ->and(queue($h)->failed())->toBe([])
        ->and(workflows($h)->progress($id)?->status)->toBe(WorkflowRecord::FINISHED);
});

it('takes a signal only in the tenant and panel it was started in', function () {
    $h = harness();
    $h->memberships->staff = ['u1'];
    $id = $h->orm->actAs('acme', fn (): string => startAsStaff($h, new AwaitsCollection()));

    expect(workflows($h)->signal($id, 'collected'))->toBeFalse('no tenant')
        ->and($h->orm->actAs('globex', fn () => $h->panels->within('admin', fn () => workflows($h)->signal($id, 'collected'))))->toBeFalse('another tenant')
        ->and($h->orm->actAs('acme', fn () => workflows($h)->signal($id, 'collected')))->toBeFalse('no panel')
        ->and($h->orm->actAs('acme', fn () => $h->panels->within('admin', fn () => workflows($h)->signal($id, 'collected'))))->toBeTrue()
        ->and(workflows($h)->signal('no-such-workflow', 'collected'))->toBeFalse();
});

it('refuses a signal inside a loader, across tenants, of a name it could not keep, or of data that is not plain or too big', function () {
    $h = harness();
    $id = workflows($h)->start(new AwaitsCollection());

    expect(fn () => $h->orm->readOnly(fn () => workflows($h)->signal($id, 'collected')))->toThrow(ReadOnlyViolation::class)
        ->and(fn () => $h->orm->acrossTenants(fn () => workflows($h)->signal($id, 'collected')))->toThrow(\LogicException::class)
        ->and(fn () => workflows($h)->signal($id, 'no spaces'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => workflows($h)->signal($id, 'collected', ['at' => new \DateTimeImmutable()]))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => workflows($h)->signal($id, 'collected', ['blob' => str_repeat('x', 9000)]))->toThrow(\LengthException::class);
});

it('reads progress only where it was started, by its starter unless the application asks for anyone\'s', function () {
    $h = harness();
    $h->memberships->staff = ['u1', 'u2'];
    $id = $h->orm->actAs('acme', fn (): string => startAsStaff($h, new AwaitsCollection()));
    $h->users->epochs['u2'] = 'e1';

    $as = fn (string $user, string $tenant, ?string $panel, bool $any = false) => $h->auth->actingAs(
        new User($user),
        fn () => $h->orm->actAs($tenant, fn () => $panel === null ? workflows($h)->progress($id, $any) : $h->panels->within($panel, fn () => workflows($h)->progress($id, $any))),
        'e1',
    );

    expect($as('u1', 'acme', 'admin')?->user)->toBe('u1')
        ->and($as('u1', 'acme', 'admin')?->workflow)->toBe(AwaitsCollection::class)
        ->and($as('u2', 'acme', 'admin'))->toBeNull('another staff member')
        ->and($as('u2', 'acme', 'admin', any: true)?->status)->toBe(WorkflowRecord::QUEUED)
        ->and($as('u1', 'globex', 'admin', any: true))->toBeNull('another tenant')
        ->and($as('u1', 'acme', null, any: true))->toBeNull('another panel');
});

it('records how it ended: failed after its last try, refused when its starter moved epoch', function () {
    $h = harness();
    $failing = workflows($h)->start(new Flaky());
    for ($run = 0; $run < 5; $run++) {
        $h->work();
        $h->now += 60;
    }
    expect(workflows($h)->progress($failing)?->status)->toBe(WorkflowRecord::FAILED);

    $h->users->epochs['u1'] = 'e1';
    $refused = $h->auth->actingAs(new User('u1'), fn () => workflows($h)->start(new ThreeSteps()), 'e1');
    $h->work();
    $h->users->epochs['u1'] = 'e2';
    $h->now += 60;
    $h->work();

    expect($h->auth->actingAs(new User('u1'), fn () => workflows($h)->progress($refused), 'e2')?->status)->toBe(WorkflowRecord::REFUSED);
});

it('refuses an edited deadline: the wait\'s is sealed in the checkpoint', function () {
    $h = harness();
    $id = workflows($h)->start(new AwaitsCollection());
    $h->work();
    $payload = queue($h)->payload($id);
    \assert($payload !== null);

    $edited = preg_replace('/"waits":\{"collected":(\d+)\}/', '"waits":{"collected":9999999999}', $payload);
    expect($edited)->not->toBe($payload)
        ->and(fn () => $h->runner->open((string) $edited))->toThrow(\Tinvar\Jobs\JobRefused::class);
});

it('cannot wait on the sync queue, which runs it here and now', function () {
    $h = harness();
    $sync = new SyncQueue($h->runner);
    $dispatcher = new \Tinvar\Jobs\Dispatcher($sync, $h->orm, $h->signer, $h->auth, $h->panels, $h->registry, fn (): int => $h->now);
    $workflows = new Workflows($dispatcher, $h->container->resolver(), new \Tinvar\Workflows\ArrayWorkflowStore(), $h->orm, $h->auth, $h->panels);
    $h->container->instance(Workflows::class, $workflows);

    expect(fn () => $workflows->start(new AwaitsCollection()))->toThrow(\LogicException::class, 'waits on a signal');
});

it('keeps its records in the database, signals once a name, and forgets the ended ones', function () {
    $connection = \Tinvar\Orm\PdoConnection::sqlite(':memory:', 'workflows');
    \Tinvar\Workflows\DatabaseWorkflowStore::define(\Tinvar\Tests\Jobs\Fixtures\schemaOf($connection));
    $store = new \Tinvar\Workflows\DatabaseWorkflowStore($connection);
    $record = new WorkflowRecord('w-1', AwaitsCollection::class, 'acme', 'admin', 'u1', WorkflowRecord::QUEUED, null, 0, null, 100, 100);
    $store->start($record);
    $store->start(new WorkflowRecord('w-1', ThreeSteps::class, 'globex', '', '', WorkflowRecord::QUEUED, null, 0, null, 1, 1));

    expect($store->find('w-1'))->toEqual($record)
        ->and($store->signal('w-1', 'collected', '{"by":"w"}', 110))->toBeTrue()
        ->and($store->signal('w-1', 'collected', '{"by":"x"}', 111))->toBeFalse()
        ->and($store->received('w-1', 'collected'))->toBe('{"by":"w"}')
        ->and($store->received('w-1', 'other'))->toBeNull();

    $store->update('w-1', WorkflowRecord::WAITING, 'collected', 1, 700, 120);
    expect($store->find('w-1')?->wakeAt)->toBe(700);
    $store->update('w-1', WorkflowRecord::FINISHED, null, 3, null, 130);
    $store->update('w-1', WorkflowRecord::RUNNING, 'late', 4, null, 140);
    expect($store->find('w-1')?->status)->toBe(WorkflowRecord::FINISHED)
        ->and($store->find('w-1')?->finishedAt)->toBe(130)
        ->and($store->prune(130))->toBe(0)
        ->and($store->prune(131))->toBe(1)
        ->and($store->find('w-1'))->toBeNull()
        ->and($store->received('w-1', 'collected'))->toBeNull();
});
