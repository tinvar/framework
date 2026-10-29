<?php

declare(strict_types=1);

namespace Tinvar\Features;

/**
 * The flags a piece of work read, and what each was: what an answer kept
 * past its request must be keyed by -- "the feature flags the query read" --
 * or not be kept at all.
 *
 * Every read of every flag is reported here (record()), by Features, to the
 * recorders open at the time. Static, so no wiring can leave a cache without
 * one: a QueryRunner handed no Features still hears every read the
 * application's Features makes.
 *
 * Two kinds of recorder, since query bodies run side by side in fibers:
 *
 *   - task(): one fiber's work, a query body. It hears the reads made in its
 *     own fiber. A read made in a fiber with no recorder of its own -- a
 *     fiber a body started, the main context between ticks -- cannot be
 *     told apart from this task's, so it taints every task recorder open:
 *     whatever they were recording is uncacheable. Fail closed.
 *   - span(): everything done while it is open, in any fiber -- a static
 *     page's whole render, whose file is sent to everyone.
 *
 * A recorder hears nothing once its work has returned or thrown.
 */
final class FlagReads
{
    /** @var list<self> the recorders open now, oldest first */
    private static array $open = [];

    /** @var array<string, bool> flag => the value read, in the order first read */
    private array $read = [];

    private bool $tainted = false;

    /**
     * The fiber a task recorder belongs to: null for the main context, or a
     * span. Weak, so a recorder never keeps a finished fiber alive.
     *
     * @var \WeakReference<object>|null
     */
    private ?\WeakReference $fiber = null;

    private bool $span = false;

    /**
     * Runs $work as one task, recording the reads made in the fiber it runs
     * in. Called from inside that fiber -- the task's own closure -- or in
     * the main context, which it then holds for the duration.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function task(\Closure $work): mixed
    {
        $fiber = \Fiber::getCurrent();
        $this->fiber = $fiber === null ? null : \WeakReference::create($fiber);
        $this->span = false;

        return $this->open($work);
    }

    /**
     * Runs $work, recording every read made while it runs, in any fiber.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function span(\Closure $work): mixed
    {
        $this->fiber = null;
        $this->span = true;

        return $this->open($work);
    }

    /**
     * Tells every open recorder that $flag was read as $active, in the
     * fiber running now.
     */
    public static function record(string $flag, bool $active): void
    {
        if (self::$open === []) {
            return;
        }

        $fiber = \Fiber::getCurrent();
        $claimed = false;
        foreach (self::$open as $recorder) {
            if (!$recorder->span && $recorder->belongsTo($fiber)) {
                $claimed = true;
                break;
            }
        }

        foreach (self::$open as $recorder) {
            if ($recorder->span || $recorder->belongsTo($fiber)) {
                $recorder->read[$flag] ??= $active;
                // Read twice in one task with two values -- a flag changed
                // between the two -- and no one value describes the work.
                if ($recorder->read[$flag] !== $active) {
                    $recorder->tainted = true;
                }
            } elseif (!$claimed) {
                $recorder->tainted = true;
            }
        }
    }

    /**
     * Tells every open recorder that something was read no value of the
     * caller's can describe -- a flag for a scope the code named, or the
     * scope itself -- so nothing being recorded is kept.
     */
    public static function unattributable(): void
    {
        foreach (self::$open as $recorder) {
            $recorder->tainted = true;
        }
    }

    /**
     * Closes every recorder still open, for the reset contract: a fiber
     * that never finished leaves none behind to hear the next request.
     */
    public static function closeAll(): void
    {
        self::$open = [];
    }

    /**
     * The flags read, each with its value, sorted by name; meaningful only
     * when clean() or tainted() says it may be trusted.
     *
     * @return array<string, bool>
     */
    public function flags(): array
    {
        $read = $this->read;
        ksort($read, SORT_STRING);

        return $read;
    }

    /** Whether a read could not be attributed, or was not one value: the work's answer is no one variant's. */
    public function tainted(): bool
    {
        return $this->tainted;
    }

    /** Nothing read, and nothing that could have been. */
    public function clean(): bool
    {
        return !$this->tainted && $this->read === [];
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function open(\Closure $work): mixed
    {
        self::$open[] = $this;
        try {
            return $work();
        } finally {
            self::$open = array_values(array_filter(self::$open, fn (self $r): bool => $r !== $this));
        }
    }

    /**
     * Whether this task recorder is for $fiber; a span is for no fiber in particular.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed>|null $fiber
     */
    private function belongsTo(?\Fiber $fiber): bool
    {
        if ($this->span) {
            return false;
        }
        if ($this->fiber === null) {
            return $fiber === null;
        }

        return $fiber !== null && $this->fiber->get() === $fiber;
    }
}
