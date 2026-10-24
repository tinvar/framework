<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * How a job is queued: which queue, how often it is tried, how long to wait
 * between tries, and how long one try may run.
 *
 *     #[Queue('mail', tries: 5, backoff: [10, 60, 300])]
 *     final readonly class SendReceipt implements Job { ... }
 *
 * "Attributes first": a queue is declared on the code it describes, as a
 * route or a schedule is, so there is no queue config to keep in step with
 * the classes. A job with none is on `default` with these defaults.
 *
 * `backoff` is seconds before each retry, in order; the last one repeats.
 * `timeout` bounds one try, after which it counts as failed. `asUser` runs
 * the job as the user who dispatched it, verified when it runs (see
 * Jobs\JobRunner) -- off by default, so a job that never asked for a user
 * cannot come to rely on one.
 *
 * On a listener class, the same attribute queues each of its #[Listener]
 * methods as a job of its own.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Queue
{
    public const string DEFAULT = 'default';

    /** @var list<int> */
    public array $backoff;

    /** @param array<array-key, mixed> $backoff whole seconds, in order */
    public function __construct(
        public string $name = self::DEFAULT,
        public int $tries = 3,
        array $backoff = [10, 60, 300],
        public int $timeout = 60,
        public bool $asUser = false,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not a queue name: letters, digits, '.', '_' and '-', up to 64.");
        }
        if ($tries < 1) {
            throw new \InvalidArgumentException('A job is tried at least once; tries must be 1 or more.');
        }
        if ($timeout < 1) {
            throw new \InvalidArgumentException('A job needs at least a second to run; timeout must be 1 or more.');
        }
        // Checked, not trusted to its declared type: an attribute's
        // arguments are whatever its author wrote.
        $seconds = [];
        foreach ($backoff as $delay) {
            if (!\is_int($delay) || $delay < 0) {
                throw new \InvalidArgumentException('Backoff is a list of whole seconds, none negative.');
            }
            $seconds[] = $delay;
        }

        $this->backoff = $seconds;
    }

    /** The options a class declares, or the defaults when it declares none. */
    public static function of(string $class): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("No class {$class}.");
        }

        // The nearest declaration: options are settings, not checks, so a
        // subclass naming its own queue replaces its parent's rather than
        // adding to it.
        $attributes = ClassAttributes::of($class, self::class);
        $nearest = $attributes === [] ? null : $attributes[array_key_last($attributes)];

        return $nearest?->newInstance() ?? new self();
    }

    /** Seconds to wait before the try after $attempt (1 for the first try). */
    public function backoffAfter(int $attempt): int
    {
        if ($this->backoff === []) {
            return 0;
        }

        return $this->backoff[min(max(0, $attempt - 1), \count($this->backoff) - 1)];
    }
}
