<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Config\FrameworkConfig;

/**
 * How long a queue worker has, from SIGTERM, before it is killed: its stop
 * budget, in whole seconds. `TINVAR_STOP_BUDGET` in the environment, or
 * `queue:work --stop-budget=S`; 70 when neither says.
 *
 * The worker spends it in three parts (Worker::onStop()):
 *
 *   1. At once, JobControl::stopping() says yes, and a job that can pause
 *      pauses at its next checkpoint and goes back on its queue.
 *   2. With the grace after a timeout and STOP_MARGIN left, a job still
 *      running is stopped (WorkerStopping) and handed back to its queue,
 *      available at once and its try not counted.
 *   3. With STOP_MARGIN left, one that would not stop is handed back all
 *      the same, its transaction rolled back, and the worker exits -- before
 *      whatever started it kills it, so the job runs again at once rather
 *      than once its reservation lapses.
 *
 * Everything that stops a worker is told the same number: `tinvar serve`
 * waits this long for its worker and scheduler before it kills them, and
 * the container's scripts derive their own waits from it, refusing a budget
 * the platform's grace period (`TINVAR_STOP_GRACE`) cannot hold. The sums
 * are here, for the shop's manifests to be held to.
 */
final class StopBudget
{
    public const string ENV = 'TINVAR_STOP_BUDGET';

    /**
     * The default: a job at #[Queue]'s default 60 s timeout, the worker's
     * grace after it, and a margin -- ADR 0063's 70 s.
     */
    public const int DEFAULT = 60 + Worker::GRACE_SECONDS + 5;

    /** Seconds left when the worker hands back a job that would not stop. */
    public const int STOP_MARGIN = 3;

    /** The least budget: the grace, the margin, and two seconds for a job to see it is stopping. */
    public const int MIN = Worker::GRACE_SECONDS + self::STOP_MARGIN + 2;

    /** An hour: past that, a stop is not what is wanted. */
    public const int MAX = 3600;

    /**
     * A budget as the environment or a flag gives it: unset is the default,
     * and anything but a whole number of seconds from MIN to MAX is refused.
     */
    public static function parse(?string $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT;
        }
        if (preg_match('/^\d{1,5}\z/', $value) !== 1) {
            throw new \InvalidArgumentException(self::ENV . " is whole seconds; got '{$value}'.");
        }

        return self::check((int) $value);
    }

    /** TINVAR_STOP_BUDGET as the framework's config read it (FrameworkConfig), held to the range. */
    public static function fromEnvironment(): int
    {
        return self::parse(FrameworkConfig::load()->stopBudget);
    }

    public static function check(int $seconds): int
    {
        if ($seconds < self::MIN || $seconds > self::MAX) {
            throw new \InvalidArgumentException(\sprintf(
                'A stop budget is from %d to %d seconds, so the grace after a timeout and the margin before the kill fit in it; got %d.',
                self::MIN,
                self::MAX,
                $seconds,
            ));
        }

        return $seconds;
    }

    /** Seconds after SIGTERM at which a job still running is stopped with WorkerStopping. */
    public static function interruptAfter(int $budget, int $graceSeconds = Worker::GRACE_SECONDS): int
    {
        return max(1, $budget - $graceSeconds - self::STOP_MARGIN);
    }

    /** Seconds after SIGTERM at which a job that would not stop is handed back and the worker exits. */
    public static function handBackAfter(int $budget): int
    {
        return $budget - self::STOP_MARGIN;
    }

    /**
     * The one container (ADR 0068): serve's wait (the budget, FrankenPHP's
     * 10 s, 5 s more), the sweep of PHP's processes (15 s, then 5 s), the
     * renderers' (80 s), and 5 s to spare. The platform's grace period must
     * be at least this.
     */
    public static function oneContainerGrace(int $budget): int
    {
        return self::oneContainerServeWait($budget) + 20 + 80 + 5;
    }

    /** How long the one container's supervisor waits for serve before it kills it. */
    public static function oneContainerServeWait(int $budget): int
    {
        return $budget + 15;
    }

    /**
     * PHP's container in the two-container shape (ADR 0071): serve's own
     * stop -- the budget, then FrankenPHP's 10 s and its renderers' 10 s --
     * and 10 s to spare.
     */
    public static function phpContainerGrace(int $budget): int
    {
        return $budget + 10 + 10 + 10;
    }

    /**
     * A pod's terminationGracePeriodSeconds: the 5 s preStop, PHP's
     * container, the renderers' sidecar after it (80 s), and 15 s to spare.
     */
    public static function podGrace(int $budget): int
    {
        return 5 + self::phpContainerGrace($budget) + 80 + 15;
    }
}
