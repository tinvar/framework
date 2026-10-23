<?php

declare(strict_types=1);

namespace Tinvar\Container;

/** How long one instance of a service is shared. */
enum Lifetime
{
    /** A new instance every time it is asked for. */
    case Transient;

    /**
     * One instance per request. Cleared by the Kernel's reset contract, so
     * nothing a request put in it reaches the next one.
     */
    case Request;

    /**
     * One instance for the worker's life, across requests. Only for services
     * that hold no per-request state: a worker serves many users in turn.
     *
     * The container refuses a Request-lived service anywhere in its graph.
     * It cannot see a Transient one that COPIES request state when built --
     * `$this->userId = $auth->id()` -- which a Worker service holding it
     * keeps for good. Hold AuthContext itself, whose user() is read live,
     * never a snapshot of it.
     */
    case Worker;
}
