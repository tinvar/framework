<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * Which request each shared data provider instance was built for, so that
 * one handed out again in a later request is refused.
 *
 * SharedRegistry refuses a provider marked #[Dependency(Lifetime::Worker)],
 * but it reads the class, and a resolver decides the instance: a container
 * that binds the provider worker-lived, or holds an instance(), hands every
 * request the one object, and whatever it memoized for the first visitor
 * reaches the next (ADR 0060). The Kernel keeps one of these, and ends its
 * request with the rest of the request's state; the map is weak, so it
 * holds no provider the resolver let go of.
 */
final class SharedProviders
{
    /** @var \WeakMap<SharedData, int> */
    private \WeakMap $served;

    private int $request = 0;

    public function __construct()
    {
        $this->served = new \WeakMap();
    }

    /**
     * Admits $provider for the request in progress: a request-lived one may
     * serve each of its renders, and a transient one is new each time.
     */
    public function admit(SharedData $provider): void
    {
        if (isset($this->served[$provider]) && $this->served[$provider] !== $this->request) {
            throw new \LogicException(
                'The resolver handed ' . $provider::class . ' to this request after an earlier one: shared data that '
                . "outlives a request carries one visitor's values into the next. Leave it transient or request-lived, "
                . 'not bound worker-lived or as an instance.',
            );
        }
        $this->served[$provider] = $this->request;
    }

    /** The request is over: an instance seen so far may not serve the next. */
    public function endRequest(): void
    {
        $this->request++;
    }
}
