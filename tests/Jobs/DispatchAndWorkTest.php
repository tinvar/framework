<?php

declare(strict_types=1);

/**
 * Jobs from dispatch to done: the payload a job travels in, the context it
 * runs in, and what a worker does with each outcome.
 *
 * "Jobs run as the dispatching tenant", "every non-HTTP context ... has a
 * defined panel, serialized with the work", and the reset contract runs "at
 * the end of each request and job" (tinvar-spec.md). These run a worker in
 * this process over a queue in memory, so a test can move the clock for
 * backoff and read what each job saw.
 */

namespace Tinvar\Tests\Jobs\DispatchAndWork;

use Tinvar\Jobs\JobRefused;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Tests\Jobs\Fixtures\Address;
use Tinvar\Tests\Jobs\Fixtures\AdminWork;
use Tinvar\Tests\Jobs\Fixtures\AlwaysFails;
use Tinvar\Tests\Jobs\Fixtures\AsDispatcher;
use Tinvar\Tests\Jobs\Fixtures\CarriesClosure;
use Tinvar\Tests\Jobs\Fixtures\CarriesValues;
use Tinvar\Tests\Jobs\Fixtures\Colour;
use Tinvar\Tests\Jobs\Fixtures\FailsOnce;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Hooks;
use Tinvar\Tests\Jobs\Fixtures\LeavesState;
use Tinvar\Tests\Jobs\Fixtures\RecordContext;
use Tinvar\Tests\Jobs\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Script;

require_once __DIR__ . '/Fixtures/jobs.php';

function queueOf(Harness $h): ArrayQueue
{
    \assert($h->queue instanceof ArrayQueue);

    return $h->queue;
}

it('runs a dispatched job on the worker, and deletes it', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new RecordContext(note: 'hello'));

    expect($h->queue->size('default'))->toBe(1)
        ->and($h->work())->toBe(1)
        ->and($h->queue->size('default'))->toBe(0)
        ->and($h->journal()->entries[0]['note'])->toBe('hello');
});

it('runs a job as the tenant that dispatched it, and reads its models again in that tenant', function () {
    $h = new Harness();
    $script = $h->orm->actAs('acme', fn () => $h->orm->find(Script::class, $h->fixture->scripts['acme'][0]));
    $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new RecordContext($script, 'acme')));
    $h->dispatcher->dispatch(new RecordContext(note: 'nobody'));

    // The worker itself runs as no tenant, whatever it ran last.
    $h->work();

    expect($h->journal()->entries)->sequence(
        fn ($e) => $e->tenant->toBe('acme')->script->toBe('acme-script-1'),
        fn ($e) => $e->tenant->toBe('')->script->toBeNull(),
    );
});

it('refuses a job whose tenant was edited on the queue, rather than run it as the new one', function () {
    $h = new Harness();
    $script = $h->orm->actAs('acme', fn () => $h->orm->find(Script::class, $h->fixture->scripts['acme'][0]));
    $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new RecordContext($script)));

    // Someone with the database rewrites the row to run as globex.
    $pending = queueOf($h)->pushed()[0];
    $forged = new ArrayQueue();
    $forged->push(new \Tinvar\Queue\PendingJob($pending->id, 'default', str_replace('"tenant":"acme"', '"tenant":"globex"', $pending->payload), 0));
    $h2 = new Harness($forged, $h->fixture);
    $h2->work();

    expect($h2->journal()->entries)->toBe([])
        ->and($forged->failed())->toHaveCount(1)
        ->and($forged->failed()[0]->error)->toContain('does not verify');
});

it('refuses dispatch inside acrossTenants(), where no one tenant is dispatching', function () {
    $h = new Harness();

    expect(fn () => $h->orm->acrossTenants(fn () => $h->dispatcher->dispatch(new RecordContext())))
        ->toThrow(\LogicException::class, 'acrossTenants()');
});

it('runs a job in the panel it was dispatched in, or the one it declares', function () {
    $h = new Harness();
    $h->panels->within('reseller', fn () => $h->dispatcher->dispatch(new RecordContext(note: 'reseller')));
    $h->dispatcher->dispatch(new AdminWork());
    $h->dispatcher->dispatch(new RecordContext(note: 'none'));
    $h->work();

    expect(array_column($h->journal()->entries, 'panel'))->toBe(['reseller', 'admin', null]);
});

it('refuses a job dispatched in a panel the application no longer defines', function () {
    $h = new Harness();
    $h->registry = new \Tinvar\Auth\PanelRegistry(new \Tinvar\Auth\PanelDefinition('admin'), new \Tinvar\Auth\PanelDefinition('ghost'));
    $dispatcher = new \Tinvar\Jobs\Dispatcher($h->queue, $h->orm, $h->signer, $h->auth, $h->panels, $h->registry);
    $h->panels->within('ghost', fn () => $dispatcher->dispatch(new RecordContext()));

    $h->work();

    expect(queueOf($h)->failed()[0]->error)->toContain("panel 'ghost', which the application does not define");
});

it('runs a job that asks for it as its dispatcher, on the same auth epoch', function () {
    $h = new Harness();
    $h->users->epochs['u1'] = 'e1';
    $h->auth->actingAs(new User('u1'), fn () => $h->dispatcher->dispatch(new AsDispatcher()), 'e1');
    // A plain job carries nobody, even dispatched by someone.
    $h->auth->actingAs(new User('u1'), fn () => $h->dispatcher->dispatch(new RecordContext()), 'e1');
    $h->work();

    expect($h->journal()->entries[0])->toBe(['user' => 'u1', 'epoch' => 'e1', 'panel' => null])
        ->and($h->journal()->entries[1]['user'])->toBeNull();
});

it('refuses a job running as a user whose auth epoch has moved, or who is gone', function () {
    $h = new Harness();
    $h->users->epochs = ['u1' => 'e1', 'u2' => 'e1'];
    $h->auth->actingAs(new User('u1'), fn () => $h->dispatcher->dispatch(new AsDispatcher()), 'e1');
    $h->auth->actingAs(new User('u2'), fn () => $h->dispatcher->dispatch(new AsDispatcher()), 'e1');

    // A role change moves u1's epoch; u2 is deleted.
    $h->users->epochs = ['u1' => 'e2'];
    $h->work();

    $errors = array_map(static fn ($f) => $f->error, queueOf($h)->failed());
    expect($h->journal()->entries)->toBe([])
        ->and(implode("\n", $errors))->toContain('another auth epoch')->toContain('is gone');
});

it('refuses dispatch of a job that runs as its dispatcher when nobody is signed in', function () {
    $h = new Harness();

    expect(fn () => $h->dispatcher->dispatch(new AsDispatcher()))->toThrow(\LogicException::class, 'nobody is signed in');
});

it('holds a job running as a user to their membership of its panel, in its tenant', function () {
    $h = new Harness();
    $h->users->epochs = ['staff' => '', 'customer' => ''];
    $h->memberships->staff = ['staff'];
    foreach (['staff', 'customer'] as $id) {
        $h->auth->actingAs(new User($id), fn () => $h->panels->within('admin', fn () => $h->dispatcher->dispatch(new AsDispatcher())));
    }
    $h->work();

    expect($h->journal()->entries)->toBe([['user' => 'staff', 'epoch' => '', 'panel' => 'admin']])
        ->and(queueOf($h)->failed()[0]->error)->toContain("not a member of panel 'admin'");
});

it('resets the identity map, the user, the tenant, request-lived services and a transaction left open between jobs', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new RecordContext(note: 'first'));
    $h->dispatcher->dispatch(new LeavesState());
    $h->dispatcher->dispatch(new RecordContext(note: 'after'));
    $h->work();

    [$first, , $after] = $h->journal()->entries;
    expect($after['tenant'])->toBe('')
        ->and($after['user'])->toBeNull()
        ->and($after['mapped'])->toBe(0)
        ->and($after['perJob'])->not->toBe($first['perJob'])
        // The row written inside the transaction the job left open was
        // rolled back with it, and the job was still recorded as done.
        ->and($h->fixture->connection->select("SELECT COUNT(*) AS n FROM scripts WHERE id = 'leak'")[0]['n'])->toBe(0)
        ->and($h->queue->size('default'))->toBe(0)
        ->and(queueOf($h)->failed())->toBe([]);
});

it('retries a failed job with its backoff, then fails it for good and runs its failed() hook', function () {
    Hooks::$failed = [];
    $h = new Harness();
    $h->dispatcher->dispatch(new AlwaysFails('x'));
    $id = queueOf($h)->pushed()[0]->id;

    expect($h->work())->toBe(1)
        ->and(queueOf($h)->availableAt($id))->toBe($h->now + 5);

    // Not before its backoff.
    expect($h->work())->toBe(0);
    $h->now += 5;
    expect($h->work())->toBe(1)
        ->and(queueOf($h)->availableAt($id))->toBe($h->now + 30);

    $h->now += 30;
    $h->work();

    expect($h->journal()->entries)->toHaveCount(3)
        ->and(queueOf($h)->failed())->toHaveCount(1)
        ->and(queueOf($h)->failed()[0]->error)->toContain('failing on purpose: x')
        ->and(Hooks::$failed)->toBe(['failing on purpose: x']);
});

it('runs a job that fails once and then works, and keeps no failure', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new FailsOnce());
    $h->work();
    $h->work();

    expect($h->journal()->entries)->toHaveCount(2)
        ->and(queueOf($h)->failed())->toBe([])
        ->and($h->queue->size('default'))->toBe(0);
});

it('fails a job taken more often than it may be tried, and puts it back on retry', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new FailsOnce());
    $id = queueOf($h)->pushed()[0]->id;

    // Taken twice and never finished, as by workers that died: each time
    // the reservation lapses it is available again, its attempt counted.
    foreach ([1, 2] as $ignored) {
        expect($h->queue->reserve(['default'], $h->now, 60))->not->toBeNull();
        $h->now += 61;
    }
    $h->work();

    expect($h->journal()->entries)->toBe([])
        ->and(queueOf($h)->failed())->toHaveCount(1)
        ->and(queueOf($h)->failed()[0]->error)->toContain('the last try never finished')
        ->and($h->queue->retry($id, $h->now))->toBeTrue()
        ->and($h->queue->failed())->toBe([]);

    $h->work();
    $h->work();
    expect($h->journal()->entries)->toHaveCount(2)
        ->and($h->queue->size('default'))->toBe(0);
});

it('carries lists, dates, enums, floats and Queueable values, and rebuilds them as they were', function () {
    $h = new Harness();
    $at = new \DateTimeImmutable('2026-10-24T10:11:12.345678+02:00');
    $h->dispatcher->dispatch(new CarriesValues(
        list: ['a' => 1, 2 => [true, null, 'x'], 'nested' => ['deeper' => 1.5]],
        at: $at,
        colour: Colour::Blue,
        address: new Address('1 Main St', Colour::Red),
        ratio: 2.0,
    ));
    $h->work();

    $job = $h->journal()->entries[0]['job'];
    expect($job)->toBeInstanceOf(CarriesValues::class)
        ->and($job->list)->toBe(['a' => 1, 2 => [true, null, 'x'], 'nested' => ['deeper' => 1.5]])
        ->and($job->at->format('Y-m-d\TH:i:s.uP'))->toBe('2026-10-24T10:11:12.345678+02:00')
        ->and($job->colour)->toBe(Colour::Blue)
        ->and($job->address)->toEqual(new Address('1 Main St', Colour::Red))
        ->and($job->ratio)->toBe(2.0);
});

it('refuses at dispatch what cannot travel: a closure, an unsaved model', function () {
    $h = new Harness();

    expect(fn () => $h->dispatcher->dispatch(new CarriesClosure(static fn () => null)))
        ->toThrow(\InvalidArgumentException::class, 'Closure, which cannot travel')
        ->and(fn () => $h->dispatcher->dispatch(new RecordContext(new Script())))
        ->toThrow(\InvalidArgumentException::class, 'has not been saved');
});

it('refuses a job whose model is gone by the time it runs', function () {
    $h = new Harness();
    $script = $h->orm->actAs('acme', fn () => $h->orm->find(Script::class, $h->fixture->scripts['acme'][0]));
    $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new RecordContext($script)));
    $h->fixture->connection->execute('DELETE FROM scripts WHERE id = ?', [$script->id]);
    $h->work();

    expect(queueOf($h)->failed()[0]->error)->toContain('is gone, or not this tenant\'s');
});

it('opens only payloads this application sealed', function () {
    $h = new Harness();

    expect(fn () => $h->runner->open('not a payload'))->toThrow(JobRefused::class)
        ->and(fn () => $h->runner->open('AAAA.{"v":1}'))->toThrow(JobRefused::class, 'does not verify');
});
