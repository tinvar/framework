<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * A gate run after its entry point's checks (EntryGates) that answered the
 * request itself, without calling $next: its answer, carried out of the
 * runner to the gate's place in the stack, and returned from there as the
 * request's.
 *
 * @internal thrown by EntryGates::enforce(), caught by the gate's place in the stack
 */
final class GateAnswered extends \RuntimeException
{
    public function __construct(public readonly Middleware $gate, public readonly Response $response)
    {
        parent::__construct($gate::class . ' answered the request itself.');
    }
}
