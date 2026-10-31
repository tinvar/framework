<?php

declare(strict_types=1);

namespace Tinvar\Tests\Workflows\Fixtures;

use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Queue;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Queue\Wakes;
use Tinvar\Workflows\ArrayWorkflowStore;
use Tinvar\Workflows\Steps;
use Tinvar\Workflows\WorkflowStore;
use Tinvar\Workflows\StepTaken;
use Tinvar\Workflows\Workflow;
use Tinvar\Workflows\Workflows;

require_once __DIR__ . '/../../Jobs/Fixtures/jobs.php';

/** What each step saw, as it ran. */
final class Seen
{
    /** @var list<array<string, mixed>> */
    public static array $steps = [];

    /** @var list<string> */
    public static array $failed = [];

    public static function reset(): void
    {
        self::$steps = [];
        self::$failed = [];
    }
}

/** Three steps and a sleep, each recording the context it ran in. */
final readonly class ThreeSteps implements Workflow
{
    public function __construct(public string $note = '', public ?Script $script = null) {}

    public function run(Steps $steps, Orm $orm, AuthContext $auth, PanelContext $panels): void
    {
        $record = fn (string $step): array => Seen::$steps[] = [
            'step' => $step,
            'note' => $this->note,
            'tenant' => $orm->tenant(),
            'user' => $auth->id(),
            'epoch' => $auth->epoch(),
            'panel' => $panels->currentOrNull(),
            'script' => $this->script?->name,
        ];

        $first = $steps->step('first', static function () use ($record): int {
            $record('first');

            return 41;
        });
        $steps->sleep('wait', 60);
        $second = $steps->step('second', static function () use ($record, $first): int {
            $record('second');

            return $first + 1;
        });
        $steps->step('third', static function () use ($record, $second): array {
            $record('third');

            return ['answer' => $second];
        });
    }
}

/** In the admin panel, whoever starts it. */
#[Panel('admin')]
final readonly class AdminFlow implements Workflow
{
    public function run(Steps $steps, PanelContext $panels, AuthContext $auth): void
    {
        $steps->step('one', static function () use ($panels, $auth): null {
            Seen::$steps[] = ['step' => 'one', 'panel' => $panels->currentOrNull(), 'user' => $auth->id()];

            return null;
        });
        $steps->step('two', static function () use ($panels): null {
            Seen::$steps[] = ['step' => 'two', 'panel' => $panels->currentOrNull()];

            return null;
        });
    }
}

/** Its second step fails until told not to, and it says when it has failed for good. */
#[Queue(tries: 2, backoff: [0])]
final readonly class Flaky implements Workflow
{
    public function run(Steps $steps): void
    {
        $steps->step('once', static function (): string {
            Seen::$steps[] = ['step' => 'once'];

            return 'done';
        });
        $steps->step('flaky', static function (): string {
            Seen::$steps[] = ['step' => 'flaky'];

            throw new \RuntimeException('the carrier is down');
        });
    }

    public function failed(\Throwable $error): void
    {
        Seen::$failed[] = $error->getMessage();
    }
}

/** Catches the end of its run, and tries to take another step anyway. */
final readonly class Greedy implements Workflow
{
    public function run(Steps $steps): void
    {
        try {
            $steps->step('a', static fn (): int => 1);
        } catch (StepTaken) {
            // Carry on, as if the run had not ended.
        }
        $steps->step('b', static function (): int {
            Seen::$steps[] = ['step' => 'b'];

            return 2;
        });
    }
}

/** Asks for one step twice. */
#[Queue(tries: 1)]
final readonly class Twice implements Workflow
{
    public function run(Steps $steps): void
    {
        $steps->step('same', static fn (): int => 1);
        $steps->step('same', static fn (): int => 2);
    }
}

/** A step returning what cannot be kept. */
#[Queue(tries: 1)]
final readonly class KeepsAnObject implements Workflow
{
    public function run(Steps $steps): void
    {
        $steps->step('object', static fn (): \stdClass => new \stdClass());
    }
}

/** Charges once, then returns what a checkpoint cannot keep: an object, or too much. */
#[Queue(tries: 3, backoff: [0])]
final readonly class ChargesThenKeepsTooMuch implements Workflow
{
    public function __construct(public bool $large = false) {}

    public function run(Steps $steps): void
    {
        $large = $this->large;
        $steps->step('charge', static function () use ($large): mixed {
            Seen::$steps[] = ['step' => 'charge'];

            return $large ? str_repeat('x', 20_000) : new \stdClass();
        });
    }

    public function failed(\Throwable $error): void
    {
        Seen::$failed[] = $error->getMessage();
    }
}

/** Asks for a step inside another. */
#[Queue(tries: 1)]
final readonly class Nested implements Workflow
{
    public function run(Steps $steps): void
    {
        $steps->step('outer', static fn (): int => $steps->step('inner', static fn (): int => 1));
    }
}

/** A job that runs as its starter, or a plain one, saying who it ran as. */
final readonly class WhoRan implements \Tinvar\Jobs\Job
{
    public function __construct(public string $name) {}

    public function handle(AuthContext $auth): void
    {
        Seen::$steps[] = ['step' => $this->name, 'user' => $auth->id()];
    }
}

final readonly class StarterRan implements \Tinvar\Jobs\Job, \Tinvar\Jobs\RunsAsStarter
{
    public function __construct(public string $name) {}

    public function handle(AuthContext $auth): void
    {
        Seen::$steps[] = ['step' => $this->name, 'user' => $auth->id()];
    }
}

/** One at a time per order, by its own uniqueId(). */
#[Queue(unique: 600)]
final readonly class OnePerOrder implements Workflow, \Tinvar\Jobs\UniqueJob
{
    public function __construct(public string $order) {}

    public function uniqueId(): string
    {
        return $this->order;
    }

    public function run(Steps $steps): void
    {
        $steps->step('only', fn (): string => $this->order);
    }
}

/**
 * Packs, waits for the warehouse to say it collected the order, and ships
 * with what it said -- or, with no word in an hour, flags it.
 */
#[Queue(releases: 3)]
final readonly class AwaitsCollection implements Workflow
{
    public function __construct(public string $order = 'o-1') {}

    public function run(Steps $steps): void
    {
        $steps->step('pack', function (): string {
            Seen::$steps[] = ['step' => 'pack', 'order' => $this->order];

            return 'packed';
        });
        $collected = $steps->waitFor('collected', 3600, poll: 600);
        $steps->step('ship', static function () use ($collected): null {
            Seen::$steps[] = ['step' => $collected === null ? 'flag' : 'ship', 'collected' => $collected];

            return null;
        });
    }
}

/** Keeps most of what a checkpoint holds, then waits for an answer. */
final readonly class KeepsALot implements Workflow
{
    public function run(Steps $steps): void
    {
        $steps->step('keep', static fn (): string => str_repeat('k', 13_000));
        $answer = $steps->waitFor('answer', 3600);
        $steps->step('read', static function () use ($answer): null {
            Seen::$steps[] = ['step' => 'read', 'answer' => $answer === null ? 'none' : 'some'];

            return null;
        });
    }
}

/** The jobs harness, with Workflows registered for the job to ask for, keeping progress and signals in memory. */
function harness(): Harness
{
    Seen::reset();
    $h = new Harness();
    $store = new ArrayWorkflowStore();
    $h->container->instance(WorkflowStore::class, $store);
    $h->container->instance(Workflows::class, new Workflows(
        $h->dispatcher,
        $h->container->resolver(),
        $store,
        $h->orm,
        $h->auth,
        $h->panels,
        $h->queue instanceof Wakes ? $h->queue : null,
        static fn (): int => $h->now,
    ));

    return $h;
}
