<?php

declare(strict_types=1);

/**
 * Workflows on the queue (ADR 0098): durable steps, each its own run of the
 * workflow's job, replayed from a sealed checkpoint, and each run in the
 * tenant, panel, user and auth epoch the workflow was started under --
 * checked again before every step.
 */

namespace Tinvar\Tests\Workflows;

use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\PendingJob;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Workflows\Fixtures\AdminFlow;
use Tinvar\Tests\Workflows\Fixtures\Flaky;
use Tinvar\Tests\Workflows\Fixtures\Greedy;
use Tinvar\Tests\Workflows\Fixtures\KeepsAnObject;
use Tinvar\Tests\Workflows\Fixtures\Nested;
use Tinvar\Tests\Workflows\Fixtures\Seen;
use Tinvar\Tests\Workflows\Fixtures\ThreeSteps;
use Tinvar\Tests\Workflows\Fixtures\Twice;
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

it('takes each step once, one per run, and sleeps between runs without holding a worker', function () {
    $h = harness();
    $id = workflows($h)->start(new ThreeSteps('order'));

    // The first step, then the sleep: back on the queue for a minute.
    expect($h->work())->toBe(2)
        ->and(array_column(Seen::$steps, 'step'))->toBe(['first'])
        ->and(queue($h)->availableAt($id))->toBe($h->now + 60)
        ->and(queue($h)->attempts($id))->toBe(0);

    $h->now += 60;
    expect($h->work())->toBe(3)
        ->and(array_column(Seen::$steps, 'step'))->toBe(['first', 'second', 'third'])
        ->and($h->queue->size('default'))->toBe(0)
        ->and(queue($h)->failed())->toBe([]);
});

it('hands a later step what an earlier one returned, read back from the checkpoint', function () {
    $h = harness();
    $id = workflows($h)->start(new ThreeSteps());
    $h->work();

    $payload = (string) queue($h)->payload($id);
    expect($payload)->toContain('"cp":{"v":1,"done":{"first":41,"wait":{"slept":60}}}');

    $h->now += 60;
    $h->work();

    // `second` returned first + 1 from the replayed 41; `third` was handed 42.
    expect(Seen::$steps)->toHaveCount(3);
});

it('runs every step in the tenant, panel, user and epoch it was started under', function () {
    $h = harness();
    $h->users->epochs['u1'] = 'e1';
    $h->memberships->staff = ['u1'];
    $script = $h->orm->actAs('acme', fn () => $h->orm->find(Script::class, $h->fixture->scripts['acme'][0]));
    $h->orm->actAs('acme', fn () => $h->panels->within('admin', fn () => $h->auth->actingAs(
        new User('u1'),
        fn () => workflows($h)->start(new ThreeSteps('x', $script)),
        'e1',
    )));

    $h->work();
    $h->now += 60;
    $h->work();

    expect(Seen::$steps)->toHaveCount(3);
    foreach (Seen::$steps as $seen) {
        expect($seen)->toMatchArray(['tenant' => 'acme', 'panel' => 'admin', 'user' => 'u1', 'epoch' => 'e1', 'script' => 'acme-script-1']);
    }
});

// ADR 0100: a user sealed in a chain is read only by the jobs that ask.
it('runs a job that runs as its starter as them in a chain, and the plain jobs beside it as nobody', function () {
    $h = harness();
    $h->users->epochs['u1'] = 'e1';
    $h->auth->actingAs(new User('u1'), fn () => $h->dispatcher->chain([
        new \Tinvar\Tests\Workflows\Fixtures\WhoRan('a'),
        new \Tinvar\Tests\Workflows\Fixtures\StarterRan('b'),
        new \Tinvar\Tests\Workflows\Fixtures\WhoRan('c'),
    ]), 'e1');
    $h->work();

    expect(Seen::$steps)->toBe([['step' => 'a', 'user' => null], ['step' => 'b', 'user' => 'u1'], ['step' => 'c', 'user' => null]]);
});

it('runs as nobody when nobody started it', function () {
    $h = harness();
    workflows($h)->start(new ThreeSteps());
    $h->work();

    expect(Seen::$steps[0]['user'])->toBeNull()
        ->and(Seen::$steps[0]['epoch'])->toBe('');
});

it('ends at the next step once its starter\'s auth epoch moves', function () {
    $h = harness();
    $h->users->epochs['u1'] = 'e1';
    $h->auth->actingAs(new User('u1'), fn () => workflows($h)->start(new ThreeSteps()), 'e1');
    $h->work();

    // A role change, while it sleeps.
    $h->users->epochs['u1'] = 'e2';
    $h->now += 60;
    $h->work();

    expect(array_column(Seen::$steps, 'step'))->toBe(['first'])
        ->and(queue($h)->failed())->toHaveCount(1)
        ->and(queue($h)->failed()[0]->error)->toContain('another auth epoch');
});

it('ends when its starter is gone', function () {
    $h = harness();
    $h->users->epochs['u1'] = 'e1';
    $h->auth->actingAs(new User('u1'), fn () => workflows($h)->start(new ThreeSteps()), 'e1');
    $h->work();

    $h->users->epochs = [];
    $h->now += 60;
    $h->work();

    expect(queue($h)->failed()[0]->error)->toContain('is gone');
});

it('runs in the panel it declares, and holds its starter to their membership of it before every step', function () {
    $h = harness();
    $h->users->epochs['u1'] = 'e1';
    $h->memberships->staff = ['u1'];
    $h->auth->actingAs(new User('u1'), fn () => workflows($h)->start(new AdminFlow()), 'e1');

    // The first step, then the membership taken away before the second.
    $reserved = $h->queue->reserve(['default'], $h->now, 60);
    \assert($reserved !== null);
    $h->worker->process($reserved);
    $h->memberships->staff = [];
    $h->work();

    expect(Seen::$steps)->toBe([['step' => 'one', 'panel' => 'admin', 'user' => 'u1']])
        ->and(queue($h)->failed()[0]->error)->toContain("not a member of panel 'admin'");
});

it('retries a step that throws from that step, and runs failed() after its last try', function () {
    $h = harness();
    workflows($h)->start(new Flaky());
    $h->work();

    expect(array_column(Seen::$steps, 'step'))->toBe(['once', 'flaky', 'flaky'])
        ->and(queue($h)->failed())->toHaveCount(1)
        ->and(Seen::$failed)->toBe(['the carrier is down']);
});

it('refuses a checkpoint edited on the queue, rather than skip or redo steps', function () {
    $h = harness();
    $id = workflows($h)->start(new ThreeSteps());
    $h->work();

    // Someone with the database marks every step done.
    $payload = (string) queue($h)->payload($id);
    $forged = new ArrayQueue();
    $forged->push(new PendingJob($id, 'default', str_replace('"done":{"first":41', '"done":{"third":1,"second":1,"first":41', $payload), 0));
    $h2 = new Harness($forged, $h->fixture);
    $h2->container->instance(Workflows::class, new Workflows($h2->dispatcher, $h2->container->resolver()));
    $h2->now = $h->now + 60;
    $h2->work();

    expect($forged->failed())->toHaveCount(1)
        ->and($forged->failed()[0]->error)->toContain('does not verify')
        ->and(array_column(Seen::$steps, 'step'))->toBe(['first']);
});

it('takes one new step a run, even from a run() that catches the end of its run', function () {
    $h = harness();
    $id = workflows($h)->start(new Greedy());
    $reserved = $h->queue->reserve(['default'], $h->now, 60);
    \assert($reserved !== null);
    $h->worker->process($reserved);

    // `b` waits for the next run, and the checks before it.
    expect(Seen::$steps)->toBe([])
        ->and((string) queue($h)->payload($id))->toContain('"done":{"a":1}');

    $h->work();
    expect(Seen::$steps)->toBe([['step' => 'b']]);
});

it('fails a run that asks for one step twice, or one inside another, or keeps what is not plain data', function () {
    $h = harness();
    workflows($h)->start(new Twice());
    workflows($h)->start(new KeepsAnObject());
    workflows($h)->start(new Nested());
    $h->work();

    $errors = implode("\n", array_map(static fn ($f) => $f->error, queue($h)->failed()));
    expect(queue($h)->failed())->toHaveCount(3)
        ->and($errors)->toContain("'same' was asked for twice")->toContain('plain data')->toContain("inside the step 'outer'");
});

it('refuses to start inside a loader or query body, as a job is', function () {
    $h = harness();

    expect(fn () => $h->orm->readOnly(fn () => workflows($h)->start(new ThreeSteps())))
        ->toThrow(\Tinvar\Orm\ReadOnlyViolation::class);
});
