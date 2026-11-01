<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Data\PayloadContext;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
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
    /** What a page answers. */
    public const array METHODS = ['GET', 'HEAD'];

    /** The page's view, on a payload, for the client to send back as X-Page. */
    public const string PAGE_HEADER = 'x-page';

    /** Marks a payload that holds only the segments a partial reload recomputed. */
    public const string PARTIAL_HEADER = 'x-partial';

    /**
     * @param \Closure(string): Middleware $middlewareResolver
     * @param list<class-string<Middleware>>     $globalMiddleware
     * @param bool $partialReloads whether X-Only and X-Except are honoured. Off
     *        unless the application says its renderer renders a frame's
     *        `segments` alone: one that ignored them would be handed a frame
     *        missing the data of every segment not recomputed.
     * @param EntryContext|null $entry what the chain's declarations put around
     *        it -- its tenant, its panel and the panel's stack -- ahead of its
     *        own middleware; without one, neither is resolved
     * @param (\Closure(RouteChain): void)|null $matched told of the chain the
     *        moment it matches, before anything of it runs -- so a request
     *        whose middleware or loader fails is still known by its route
     */
    public function __construct(
        private readonly RouteRegistry $routes,
        private readonly LoaderRunner $loaders,
        private readonly \Closure $middlewareResolver,
        private readonly array $globalMiddleware = [],
        private readonly bool $partialReloads = false,
        private readonly ?EntryContext $entry = null,
        private readonly ?\Closure $matched = null,
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
            // Host and path (ADR 0149): the host the Kernel resolved, or --
            // for a router driven without one -- resolved here, a host the
            // application does not serve refused with a 421.
            $domains = $this->routes->domains();
            $host = $domains === null ? null : $domains->resolve($passed);
            $chain = $this->routes->match($passed->path, $host)
                ?? throw new NotFoundException('No route matches this path.');
            if ($this->matched !== null) {
                ($this->matched)($chain);
            }

            // A page answers GET and HEAD. Anything else ran the loaders --
            // the read-only bodies prefetch runs on hover -- and answered a
            // POST or a DELETE with a 200, so a form posted at a page's URL
            // by mistake read as having worked. Refused before the route's
            // own middleware: nothing of a route runs for a method it does
            // not serve. The spec gives OPTIONS no meaning here (there is no
            // CORS surface), so it is refused like the rest, and the Allow
            // header says what would be answered.
            if (!\in_array($passed->method, self::METHODS, true)) {
                throw new HttpException(
                    405,
                    'A page answers GET and HEAD only.',
                    'method_not_allowed',
                    ['allow' => implode(', ', self::METHODS)],
                );
            }

            // The chain's own middleware can only exist once a chain does --
            // and its tenant and panel ahead of it, so the middleware, the
            // model binding and every loader run as the tenant the chain
            // names, inside its panel and behind the panel's stack.
            $pipeline = EntryContext::routePipeline($this->entry, $chain, $this->globalMiddleware, $this->middlewareResolver);

            return $pipeline->run($passed, function (Request $inner) use ($chain, $respond): Response {
                // Inside the route's middleware, like everything else that
                // decides what the page computes.
                $partial = $this->partialReloads ? PartialReload::fromRequest($inner, $chain) : null;
                $loaded = $this->loaders->run($chain, $inner, $partial);

                // The merged fields a client wants replaced rather than added
                // to. Only marks what is sent; a page load never merges, so
                // only a payload reads it (ADR 0020).
                $resets = $inner->wantsRscPayload()
                    ? PayloadContext::listFrom($inner->header('x-reset'), PayloadContext::PATH)
                    : [];
                if ($resets !== []) {
                    $loaded = $loaded->withContext($loaded->context->withResets($resets));
                }

                // One URL returns different bodies depending on the RSC header,
                // so caches must key on it or skip the route entirely.
                $response = $respond($loaded, $inner)->withAddedHeader('vary', 'RSC');

                if ($inner->header('rsc') !== '1') {
                    return $response;
                }

                // A payload refers to client components by build, so one
                // cached for the old build must not be handed to a client on
                // the new one after a deploy. Only payloads: a document load
                // is the same whatever the client was running.
                $response = $response->withAddedHeader('vary', 'X-Version')
                    // What the client says it holds changes the payload: a
                    // held once field is left out, a reset field marked, a
                    // held once-shared value read as held (ADR 0065).
                    ->withAddedHeader('vary', 'X-Reset')
                    ->withAddedHeader('vary', 'X-Have-Once')
                    ->withAddedHeader('vary', 'X-Once-Binding')
                    ->withAddedHeader('vary', 'X-Have-Shared');
                if (!$this->partialReloads) {
                    return $response;
                }

                // Which page this is, so the client can ask for part of it;
                // and, on a partial payload, that it holds only part.
                $response = $response
                    ->withHeader(self::PAGE_HEADER, $chain->page->view)
                    ->withAddedHeader('vary', 'X-Page')
                    ->withAddedHeader('vary', 'X-Only')
                    ->withAddedHeader('vary', 'X-Except');

                return $loaded->isPartial()
                    // Part of one client's page as it holds it now: never
                    // anything a cache could hand anyone, that client included.
                    ? $response->withHeader(self::PARTIAL_HEADER, '1')->withHeader('cache-control', 'private, no-store')
                    : $response;
            });
        });
    }
}
