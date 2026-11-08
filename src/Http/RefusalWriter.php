<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Writes an endpoint's refusals in the shape its own clients read (ADR
 * 0188), named by #[Refusals] on the endpoint or its class.
 *
 * Handed what the framework's own answer would say and no more: the
 * status, the code, the fixed reason, the digest the failure was logged
 * under, and the headers it must go with -- never the exception, whose
 * message names classes, rows and tenants. The headers it does not set are
 * added to what it returns, so a 429 keeps its Retry-After. A failure that
 * is not a refusal, a 500, is never handed here: the framework answers it.
 */
interface RefusalWriter
{
    public function write(HttpError $error, Request $request): Response;
}
