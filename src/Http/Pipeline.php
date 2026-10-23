<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Runs a request through a middleware stack, outermost first.
 *
 * A middleware may return early without calling $next, which is how auth,
 * throttling and CSRF refuse a request before anything else runs.
 */
final class Pipeline
{
    /** @param list<Middleware> $middleware */
    public function __construct(private readonly array $middleware = []) {}

    /** @param list<Middleware> $middleware */
    public function through(array $middleware): self
    {
        return new self([...$this->middleware, ...$middleware]);
    }

    /** @param \Closure(Request): Response $destination */
    public function run(Request $request, \Closure $destination): Response
    {
        $next = $destination;

        foreach (array_reverse($this->middleware) as $middleware) {
            $previous = $next;
            $next = static fn (Request $passed): Response => $middleware->handle($passed, $previous);
        }

        return $next($request);
    }

    /** @return list<Middleware> */
    public function middleware(): array
    {
        return $this->middleware;
    }
}
