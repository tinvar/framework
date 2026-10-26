<?php

declare(strict_types=1);

namespace Tinvar\Testing\Fakes;

use Tinvar\Support\DevMode;
use Tinvar\Support\StandIns;

/**
 * The query and action fakes: hand it to the application as its StandIns,
 * as MailFake is handed in as its transport, and a closure installed here
 * answers in place of a query's or an action's body.
 *
 * "`Query::fake(RackStats::class, fn () => ...)` and `Action::fake(...)`
 * isolate a page from its data." A test installs one with Tinvar\Testing\Query
 * and Tinvar\Testing\Action, by class or name, or here by name. It is asked
 * by the application's own runners -- the render's, the framework Kernel's
 * for actions and HTTP queries, and the testing Client's for direct calls --
 * so a fake answers a page test, a render and a direct call alike, and only
 * once the call has passed everything before its body.
 *
 * A fake is handed the arguments it names, as the body would have been: a
 * query's resolved models and values, an action's models, form requests,
 * uploads and scalars. `fn () => ...` names none, and is handed none.
 *
 * Emptied between tests by Testing::forget() and Testing::use(), so one
 * test's fake cannot answer the next one's call even where an application
 * outlives a test. Refused where the environment says production, as the
 * testing Client is.
 */
final class BodyFakes implements StandIns
{
    /** @var array<string, \Closure> by query name */
    private array $queries = [];

    /** @var array<string, \Closure> by action name */
    private array $actions = [];

    public function __construct()
    {
        $marker = DevMode::productionMarker(getenv());
        if ($marker !== null) {
            throw new \LogicException("Query and action fakes are refused: {$marker}=production.");
        }
    }

    /** Query $name answers with $body from now on, until forget(). */
    public function fakeQuery(string $name, \Closure $body): self
    {
        $this->queries[$name] = self::named($body);

        return $this;
    }

    /**
     * Action $name runs $body from now on, until forget(). $body returns an
     * ActionResult, as the action's own body must.
     */
    public function fakeAction(string $name, \Closure $body): self
    {
        $this->actions[$name] = self::named($body);

        return $this;
    }

    public function query(string $name): ?\Closure
    {
        return $this->queries[$name] ?? null;
    }

    public function action(string $name): ?\Closure
    {
        return $this->actions[$name] ?? null;
    }

    /** Every fake removed: each query and action answers with its body again. */
    public function forget(): void
    {
        $this->queries = [];
        $this->actions = [];
    }

    /**
     * $body, called as a body is -- with every argument, by name -- and
     * handed the ones it declares: PHP refuses a named argument a closure has
     * no parameter for, and `fn () => ...` has none.
     */
    private static function named(\Closure $body): \Closure
    {
        $declared = [];
        foreach (new \ReflectionFunction($body)->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                return static fn (mixed ...$arguments): mixed => $body(...$arguments);
            }
            $declared[$parameter->getName()] = true;
        }

        return static fn (mixed ...$arguments): mixed => $body(...array_intersect_key($arguments, $declared));
    }
}
