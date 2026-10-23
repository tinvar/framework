<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * What a worker serves: an application booted once, asked to handle one
 * request after another, and reset between them.
 */
interface Application
{
    /** One request, start to finish; never throws for a request's own failure. */
    public function handle(Request $request, string $rawBody = ''): Response;

    /**
     * The reset contract: whatever one request left behind -- the user, the
     * identity map, policy instances, request-lived services -- cleared
     * before the next. A worker calls it after every request, whatever
     * happened in it.
     */
    public function reset(): void;
}
