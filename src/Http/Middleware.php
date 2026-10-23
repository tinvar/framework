<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * "Middleware is PHP only: attribute on the page class, on a route group, or
 * global." (tinvar-spec.md, "Pages, loaders and routing")
 *
 * The renderer never runs middleware; it never sees a client request at all.
 */
interface Middleware
{
    /** @param \Closure(Request): Response $next */
    public function handle(Request $request, \Closure $next): Response;
}
