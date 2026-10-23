<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * The front of every request: match, run middleware, run the loaders, hand the
 * result to whatever produces the response.
 *
 * The router does not know about the renderer. It yields a LoadedRoute, and the
 * caller decides whether that becomes streamed HTML, an RSC payload or a test
 * assertion -- which is what lets most tests run without Node at all.
 */
final class Router
{
    /**
     * @param \Closure(class-string): Middleware $middlewareResolver
     * @param list<class-string<Middleware>>     $globalMiddleware
     */
    public function __construct(
        private readonly RouteRegistry $routes,
        private readonly LoaderRunner $loaders,
        private readonly \Closure $middlewareResolver,
        private readonly array $globalMiddleware = [],
    ) {}

    /**
     * @param \Closure(LoadedRoute, Request): Response $respond
     */
    public function handle(Request $request, \Closure $respond): Response
    {
        // The global stack wraps everything, including a miss. Resolving the
        // route first would skip auth, throttling, security headers, tenant
        // resolution and request logging for exactly the paths an attacker
        // enumerates -- and route enumeration is what a 404 reveals.
        $global = new Pipeline(array_map(
            fn (string $class): Middleware => ($this->middlewareResolver)($class),
            array_values(array_unique($this->globalMiddleware)),
        ));

        return $global->run($request, function (Request $passed) use ($respond): Response {
            $chain = $this->routes->match($passed->path)
                ?? throw new NotFoundException('No route matches this path.');

            // The chain's own middleware can only exist once a chain does.
            $routeMiddleware = array_values(array_diff($chain->middleware(), $this->globalMiddleware));
            $pipeline = new Pipeline(array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                $routeMiddleware,
            ));

            return $pipeline->run($passed, function (Request $inner) use ($chain, $respond): Response {
                $loaded = $this->loaders->run($chain, $inner);

                // One URL returns different bodies depending on the RSC header,
                // so caches must key on it or skip the route entirely.
                $response = $respond($loaded, $inner)->withAddedHeader('vary', 'RSC');

                // A payload refers to client components by build, so one
                // cached for the old build must not be handed to a client on
                // the new one after a deploy. Only payloads: a document load
                // is the same whatever the client was running.
                return $inner->header('rsc') === '1'
                    ? $response->withAddedHeader('vary', 'X-Version')
                    : $response;
            });
        });
    }

    public function routes(): RouteRegistry
    {
        return $this->routes;
    }
}
