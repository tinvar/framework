<?php

declare(strict_types=1);

namespace Tinvar\Events;

use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;

/**
 * Records events instead of running their listeners: the spec's "fakes for
 * mail, queue, HTTP, events", for a test about what code announces rather
 * than what hears it. Refuses dispatch where the real dispatcher does, so a
 * loader that dispatches fails under the fake too.
 */
final class EventFake implements Events
{
    /** @var list<object> */
    private array $dispatched = [];

    public function __construct(private readonly ?Orm $orm = null) {}

    public function dispatch(object $event): void
    {
        if ($this->orm?->isReadOnly() === true) {
            throw ReadOnlyViolation::write('Event dispatch', 'a loader or query body');
        }

        $this->dispatched[] = $event;
    }

    /**
     * The events of $class dispatched, in order.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    public function dispatched(string $class): array
    {
        return array_values(array_filter($this->dispatched, static fn (object $event): bool => $event instanceof $class));
    }
}
