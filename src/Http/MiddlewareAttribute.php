<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * An attribute that guards the entry point it is on with a middleware of
 * its own, handed the attribute and so its arguments:
 *
 *     #[RequiresPlan(Pro::class)]   // a billing package's: refuses a payer not on Pro
 *
 * It goes wherever #[Middleware] goes -- a page, a layout, an action, a
 * query, an endpoint, a channel, an export or import, on the class or its
 * entry method -- and is inherited as #[Middleware] is (ClassAttributes).
 * It runs later than #[Middleware]: once the entry point's #[Authorize]
 * checks have passed, as a check (EntryGates), so a caller the checks
 * refuse never learns what it would have said. The framework names no such
 * attribute: a package declares one, and the middleware() class does the
 * work, built by the application's resolver (ADR 0150).
 *
 * It fails closed. The middleware is built when a request reaches the entry
 * point, and one the resolver cannot build -- its package registered
 * nothing for it -- fails that request, never lets it past.
 */
interface MiddlewareAttribute
{
    /** @return class-string<AttributeMiddleware> the middleware that enforces this attribute */
    public function middleware(): string;
}
