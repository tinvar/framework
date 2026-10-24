<?php

declare(strict_types=1);

namespace Tinvar\Events;

/**
 * Something that hands an event to its listeners: the dispatcher, or the
 * fake a test puts in its place.
 */
interface Events
{
    public function dispatch(object $event): void;
}
