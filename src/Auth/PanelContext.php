<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * Which panel the current work is running in.
 *
 * tinvar-spec.md, "Panels and scope":
 *
 *   "Every non-HTTP context (API tokens, jobs, workflows, AI tool calls,
 *    service calls, consoles) has a defined panel, serialized with the work.
 *    A policy that reads `Panel::current()` where none is set throws."
 *
 * Throwing on unset is the point, and it is why this is not a string with a
 * default. A panel can only NARROW what a request sees, so code that asks
 * which panel it is in is code that is about to restrict something. A default
 * of 'default' would answer that question with a guess, and the guess would
 * be the widest reading — exactly backwards for a narrowing mechanism. An
 * unset panel means nobody decided, and nobody deciding is not the same as
 * deciding on everything.
 *
 * Fiber-local for the same reason as the tenant and the current user: the
 * render scheduler interleaves query bodies, and a panel switch in one fiber
 * must not become every fiber's.
 *
 * The spec writes the accessor `Panel::current()`. That spelling is a static
 * over ambient state, which this framework has avoided everywhere else that
 * request context is involved — so the context is an object, and the build
 * rules already refuse the textual form inside a `shared: true` query either
 * way.
 */
final class PanelContext
{
    private ?string $main = null;

    /** @var \WeakMap<object, string> */
    private \WeakMap $byFiber;

    public function __construct()
    {
        $this->byFiber = new \WeakMap();
    }

    /**
     * The panel this work is in.
     *
     * @throws NoPanelContext when nobody has set one
     */
    public function current(): string
    {
        return $this->currentOrNull() ?? throw new NoPanelContext();
    }

    public function currentOrNull(): ?string
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return $this->main;
        }

        return $this->byFiber[$fiber] ?? $this->main;
    }

    public function isSet(): bool
    {
        return $this->currentOrNull() !== null;
    }

    /**
     * Runs $work inside a panel, restoring whatever was there afterwards.
     *
     * Scoped rather than a bare setter, as tenancy is: a setter would leak the
     * switch into whatever ran next on the same worker.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function within(string $panel, \Closure $work): mixed
    {
        if ($panel === '') {
            throw new \InvalidArgumentException('A panel name cannot be empty; use no panel instead.');
        }

        $fiber = \Fiber::getCurrent();

        // Whether this fiber HAD an entry, not what it currently reads.
        // currentOrNull() collapses "no entry, inheriting main" into main's
        // value at that instant, and writing that back on exit CREATED an
        // entry -- permanently detaching the fiber from later main-context
        // changes. AuthContext::actingAs gets this right; this did not.
        $had = $fiber !== null && isset($this->byFiber[$fiber]);
        $previous = $fiber === null ? $this->main : ($had ? $this->byFiber[$fiber] : null);

        $this->set($panel);

        try {
            return $work();
        } finally {
            if ($fiber === null) {
                $this->main = $previous;
            } elseif ($had) {
                $this->byFiber[$fiber] = $previous;
            } else {
                unset($this->byFiber[$fiber]);
            }
        }
    }

    /**
     * Cleared at the end of every request and job, with the rest of the scope.
     *
     * A fiber suspended inside within() and resumed after a reset unwinds
     * into the FRESH map and then removes its own entry, so it cannot leave
     * anything behind for the next request -- the key is that fiber, and that
     * fiber belongs to the request that is over.
     */
    public function reset(): void
    {
        $this->main = null;
        $this->byFiber = new \WeakMap();
    }

    private function set(string $panel): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $this->main = $panel;

            return;
        }

        $this->byFiber[$fiber] = $panel;
    }
}
