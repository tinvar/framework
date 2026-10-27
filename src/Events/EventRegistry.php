<?php

declare(strict_types=1);

namespace Tinvar\Events;

use Tinvar\Attributes\ClassAttributes;
use Tinvar\Attributes\Listener;
use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Queueable;
use Tinvar\Support\KnownAttributes;

/**
 * Which methods listen for which events, read off #[Listener] attributes on
 * the classes an application registers -- as it registers its pages,
 * queries and actions, so there is no listener map to keep in step.
 *
 * A listener receives an event of its parameter's type or any subtype, so
 * a listener for an interface hears every event that implements it.
 * Checked at registration: a listener method must take exactly one
 * parameter, typed as a class or interface, and a queued listener's event
 * type must be Queueable -- an event that cannot travel would otherwise be
 * found out on the first dispatch, inside whatever transaction made it.
 */
final class EventRegistry
{
    /** @var list<ListenerDefinition> */
    private array $listeners = [];

    /** @param list<class-string> $classes */
    public function registerAll(array $classes): void
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /** @param class-string $class */
    public function register(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        // A Tinvar attribute naming no class would be matched by nothing, and
        // what it declares would not hold (KnownAttributes).
        KnownAttributes::check($reflection);

        $queued = ClassAttributes::of($reflection, Queue::class) !== [];

        $found = false;
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attribute = $method->getAttributes(Listener::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }
            $found = true;

            $where = "{$class}::{$method->getName()}()";
            $parameters = $method->getParameters();
            $type = \count($parameters) === 1 ? $parameters[0]->getType() : null;
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()) {
                throw new \InvalidArgumentException("{$where} is a #[Listener], and must take one parameter: the event, typed as its class or interface.");
            }
            $event = $type->getName();
            if (!class_exists($event) && !interface_exists($event)) {
                throw new \InvalidArgumentException("{$where} listens for {$event}, which does not exist.");
            }
            if ($method->isStatic()) {
                throw new \InvalidArgumentException("{$where} is a #[Listener], and must not be static: listeners are built by the container.");
            }
            if ($queued && !is_a($event, Queueable::class, true)) {
                throw new \InvalidArgumentException(
                    "{$where} is queued (its class has #[Queue]), so its event must travel in a job, and {$event} is not Queueable.",
                );
            }

            $options = $attribute->newInstance();
            if ($queued && $options->afterCommit) {
                // Already after the commit: the queue waits for it.
                throw new \InvalidArgumentException("{$where} is queued, which already waits for the commit; leave out afterCommit.");
            }

            $this->listeners[] = new ListenerDefinition($class, $method->getName(), $event, $options->afterCommit, $queued);
        }

        if (!$found) {
            throw new \InvalidArgumentException("{$class} was registered for events, and has no #[Listener] method.");
        }
    }

    /**
     * The listeners for $event, in the order they were registered.
     *
     * @return list<ListenerDefinition>
     */
    public function listenersFor(object $event): array
    {
        return array_values(array_filter(
            $this->listeners,
            static fn (ListenerDefinition $listener): bool => $event instanceof $listener->event,
        ));
    }

    /** @return list<ListenerDefinition> */
    public function all(): array
    {
        return $this->listeners;
    }
}
