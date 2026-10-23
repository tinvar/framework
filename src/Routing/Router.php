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
        $chain = $this->routes->match($request->path)
            ?? throw NotFoundException::route($request->path);

        // Global middleware first, then the chain's, outer layout to page.
        $classes = [...$this->globalMiddleware, ...$chain->middleware()];
        $pipeline = new Pipeline(array_map(
            fn (string $class): Middleware => ($this->middlewareResolver)($class),
            array_values(array_unique($classes)),
        ));

        return $pipeline->run($request, function (Request $passed) use ($chain, $respond): Response {
            $loaded = $this->loaders->run($chain, $passed);

            $response = $respond($loaded, $passed);

            // One URL returns different bodies depending on the RSC header, so
            // caches must key on it or skip the route entirely.
            return $response->withAddedHeader('vary', 'RSC');
        });
    }

    public function routes(): RouteRegistry
    {
        return $this->routes;
    }
}
