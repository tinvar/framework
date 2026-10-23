<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Auth\PanelContext;
use Tinvar\Http\Request;
use Tinvar\Orm\Orm;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenError;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Support\Signer;

/**
 * Re-renders the routes an action said it invalidated.
 *
 * "revalidate('racks/show') targets routes ... The response carries a fresh
 * RSC payload for affected mounted routes, each rendered through its own
 * full pipeline from a signed route token, so the page updates without
 * manual state syncing."
 *
 * The client sends the route tokens it is holding — the pages it currently
 * has mounted — and the action names the routes it invalidated. The
 * intersection is what gets re-rendered. Neither side decides alone: the
 * client cannot ask for a route the action did not invalidate, and the
 * action cannot cause a render of a page this client was never on.
 *
 * "If the pipeline denies, the action result is returned WITHOUT a payload."
 * That clause is the reason this returns outcomes rather than throwing. A
 * revalidation is a courtesy attached to a write that has already happened;
 * a page the session may no longer see must not turn a successful action
 * into a failed request. The client gets the result and no payload, which it
 * reads as "reload that route yourself" — the same thing it would do if the
 * token had expired.
 */
final class Revalidator
{
    /**
     * @param \Closure(LoadedRoute, Request): string $render produces the payload
     *        for one re-rendered route
     */
    public function __construct(
        private readonly RouteRegistry $routes,
        private readonly LoaderRunner $loaders,
        private readonly Signer $signer,
        private readonly Orm $orm,
        private readonly \Closure $render,
        private readonly ?PanelContext $panels = null,
    ) {}

    /**
     * @param list<string> $invalidated route names the action revalidated
     * @param list<string> $tokens      route tokens the client is holding
     * @param \Closure(Request): string $sessionId
     * @return array<string, string> route name => payload, for those that rendered
     */
    public function rerender(array $invalidated, array $tokens, Request $request, \Closure $sessionId): array
    {
        if ($invalidated === [] || $tokens === []) {
            return [];
        }

        $wanted = array_flip($invalidated);
        $payloads = [];

        foreach ($tokens as $raw) {
            $token = $this->verified($raw, $request, $sessionId);
            if ($token === null) {
                continue;
            }

            $claims = $token->claims;

            // Only what the action named. A client holding ten tokens does
            // not get ten re-renders because one row changed.
            if (!isset($wanted[$claims->route])) {
                continue;
            }

            $payload = $this->renderOne($claims->route, $claims->params, $claims->panel, $request);
            if ($payload !== null) {
                $payloads[$claims->route] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * A token this server will act on, or null.
     *
     * Null rather than an exception, throughout: every reason a token is
     * unusable — forged, expired, another session's, another tenant's —
     * leaves the action successful and the payload absent, which is what
     * the spec asks for and what the client already knows how to handle.
     */
    private function verified(string $raw, Request $request, \Closure $sessionId): ?RouteToken
    {
        try {
            $token = RouteToken::decode($raw, $this->signer);
        } catch (TokenError) {
            return null;
        }

        $session = $sessionId($request);
        if ($session === '' || !$token->claims->belongsTo($this->signer, $session)) {
            return null;
        }

        // The same bindings a fragment is checked against, for the same
        // reasons: a token is client-held state, and client-held state is
        // bound to (user, tenant) and purged on a panel switch.
        if ($this->orm->tenant() !== $token->claims->tenant) {
            return null;
        }

        $ambient = $this->panels?->currentOrNull();
        if ($ambient !== null && $ambient !== $token->claims->panel) {
            return null;
        }

        return $token;
    }

    /** @param array<string, string> $params */
    private function renderOne(string $route, array $params, string $panel, Request $request): ?string
    {
        $run = function () use ($route, $params, $request): ?string {
            try {
                $chain = $this->routes->chainFor($route, $params);
            } catch (RoutingException) {
                // The route was renamed or removed since the page loaded.
                // Ordinary deploy skew, not a failure of the action.
                return null;
            }

            try {
                // "Each re-render runs that route's full GET pipeline:
                // middleware, layout and page #[Authorize], loaders." The
                // runner is the same one a real GET uses, so a check cannot
                // apply on navigation and not here.
                $loaded = $this->loaders->run($chain, $request);
            } catch (\Throwable) {
                // Including a denial. The write stands; the payload does not.
                return null;
            }

            return ($this->render)($loaded, $request);
        };

        return $panel === '' || $this->panels === null
            ? $run()
            : $this->panels->within($panel, $run);
    }
}
