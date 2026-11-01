<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The middleware a MiddlewareAttribute names: handed the attribute it
 * enforces with each request, so one class serves every `#[RequiresPlan]`,
 * whatever plans each names (ADR 0150).
 */
interface AttributeMiddleware
{
    /** @param \Closure(Request): Response $next */
    public function handle(MiddlewareAttribute $attribute, Request $request, \Closure $next): Response;
}
