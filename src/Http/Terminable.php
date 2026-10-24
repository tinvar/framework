<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * An application with work to do once a response has gone: telemetry to
 * send, which no visitor should wait for.
 *
 * The worker finishes the response first -- frankenphp_finish_request(),
 * so the client has every byte and the connection is free -- then calls
 * terminate(), then runs the reset contract. A front controller with no
 * way to finish early calls it after sending.
 */
interface Terminable
{
    public function terminate(): void;
}
