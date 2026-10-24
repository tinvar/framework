<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Auth\PanelContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * Runs the rest of an entry point in the panel it belongs to.
 *
 * Ahead of the panel's guard and the rest of its stack, so each of them --
 * and everything after them, the route's own middleware, its loaders, the
 * render, the tokens it mints -- reads the panel the entry point declared
 * rather than none. Through PanelContext::within(), so the panel is the
 * request's for exactly as long as it runs.
 *
 * A request already in another panel -- one the application's global
 * middleware chose, a fragment token's -- is not moved into this one: a
 * panel only narrows, and a switch made here would be one nobody asked for.
 * As far as that request may learn, the page is not there.
 */
final readonly class EnterPanel implements Middleware
{
    public function __construct(
        private PanelContext $panels,
        private string $panel,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $current = $this->panels->currentOrNull();
        if ($current === $this->panel) {
            return $next($request);
        }

        if ($current !== null) {
            throw new HttpException(404, "The request is in panel '{$current}', not '{$this->panel}'.", 'not_found');
        }

        return $this->panels->within($this->panel, static fn (): Response => $next($request));
    }
}
