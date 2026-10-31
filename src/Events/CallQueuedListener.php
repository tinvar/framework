<?php

declare(strict_types=1);

namespace Tinvar\Events;

use Tinvar\Attributes\ClassAttributes;
use Tinvar\Attributes\Listener;
use Tinvar\Attributes\Queue;
use Tinvar\Container\Container;
use Tinvar\Jobs\DeclaredBy;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobRefused;
use Tinvar\Jobs\Queueable;

/**
 * A queued listener's call, as a job: the listener, its method, the event.
 *
 * Queued under the listener class's own #[Queue] -- its queue, tries,
 * backoff and timeout -- which the dispatcher reads from the class (see
 * EventDispatcher), not this one's.
 */
final readonly class CallQueuedListener implements Job, DeclaredBy
{
    /**
     * @param class-string $listener
     */
    public function __construct(
        public string $listener,
        public string $method,
        public Queueable $event,
    ) {}

    public function declaredBy(): string
    {
        return $this->listener;
    }

    public function handle(Container $container): void
    {
        // A class a deploy since removed: nothing about another try brings it back.
        if (!class_exists($this->listener)) {
            throw new JobRefused('listener_missing', "{$this->listener} is no longer a class.");
        }
        $definition = null;
        foreach (new \ReflectionClass($this->listener)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === $this->method && $method->getAttributes(Listener::class) !== []) {
                $definition = $method;
            }
        }
        // Only a method that still says it listens: the payload names a
        // class and a method, and a deploy since may have renamed either.
        if ($definition === null || ClassAttributes::of($this->listener, Queue::class) === []) {
            throw new JobRefused('listener_missing', "{$this->listener}::{$this->method}() is no longer a queued #[Listener].");
        }

        // Nor one whose event a deploy since retyped: called, it would throw
        // a TypeError on every try.
        if (!self::takes($definition, $this->event)) {
            throw new JobRefused('listener_missing', "{$this->listener}::{$this->method}() no longer takes " . $this->event::class . '.');
        }

        $definition->invoke($container->get($this->listener), $this->event);
    }

    /** Whether $method can be called with $event alone. */
    private static function takes(\ReflectionMethod $method, object $event): bool
    {
        if ($method->getNumberOfRequiredParameters() > 1) {
            return false;
        }
        $type = ($method->getParameters()[0] ?? null)?->getType();

        return $type === null || self::admits($type, $event);
    }

    private static function admits(\ReflectionType $type, object $event): bool
    {
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::admits($member, $event)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (!self::admits($member, $event)) {
                    return false;
                }
            }

            return true;
        }
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        $name = $type->getName();

        return \in_array($name, ['mixed', 'object'], true) || (!$type->isBuiltin() && $event instanceof $name);
    }
}
