<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Orm\Orm;
use Tinvar\Render\FragmentMinter;
use Tinvar\Render\TenantClaim;
use Tinvar\Support\Signer;

/**
 * What an application must supply before deferred fragments can be served.
 *
 * Grouped into one object rather than spread across the Kernel's constructor
 * because they are all-or-nothing: a signing key with no build registry, or a
 * build registry with no way to render a subtree, is not a configuration this
 * framework should accept halfway. An application with no <Deferred> anywhere
 * passes null and mounts no endpoint at all.
 */
final readonly class FragmentSupport
{
    /**
     * @param \Closure(\Tinvar\Render\FragmentToken, Request, array<string, mixed>, list<string>, ?\Tinvar\Data\SharedReads): Response $render
     *        renders one boundary's subtree. Its fifth argument is what the subtree may read
     *        with `shared()`, for the render channel beside the frame (ADR 0065); a closure
     *        taking four leaves every read refused.
     * @param (\Closure(string, string=): list<string>)|null $allowedQueries the originating
     *        route's query allowlist, by route name -- and, given the component
     *        a fragment renders, that component's own share of it
     *        (FragmentManifest::queriesFor(), ADR 0104). The ENDPOINT asks for
     *        this and hands the answer to $render, rather than letting the
     *        render closure choose: "PHP rejects any other name on that
     *        route's socket" is only true while the list comes from the
     *        route. A closure free to pass the whole registry would make
     *        /_fragment a query endpoint with no allowlist, no CSRF, no
     *        authentication and the wrong throttle bucket.
     * @param (\Closure(\Tinvar\Routing\LoadedRoute, Request): (string|Response))|null $renderRoute
     *        produces the payload for a route re-rendered after an action.
     *        Separate from $render because a fragment renders ONE subtree
     *        from a token and this renders a whole route from its loaders:
     *        the same closure could not honestly do both. It may answer as a
     *        page render does, with a response: the payload as its body, and
     *        in `x-live-token` what the request's
     *        RequestAttributes::LIVE_MINTER minted from the render's tags --
     *        the token the client listens with from then on, in place of the
     *        one the page was first drawn with (ADR 0048).
     * @param \Closure(Request): string $sessionId must return a real session id; an empty
     *        one is refused, because every visitor without a session would otherwise share
     *        one binding and their tokens would be interchangeable
     * @param Signer|null $routeSigner signs route tokens. A second signer, not
     *        the fragment one: Signer derives a key per purpose so a value
     *        signed for one never verifies as another, and handing one signer
     *        to both -- before the purpose was bound into the MAC -- made every
     *        fragment token a valid route token. Separate keys keep that from
     *        resting on a single mechanism.
     * @param (\Closure(list<\Tinvar\Render\FragmentToken>, Request, array<string, mixed>, list<string>, ?\Tinvar\Data\SharedReads): Response)|null $renderGroup
     *        renders the boundaries of one <Deferred group> in ONE render, as
     *        an RSC payload whose root is the list of their subtrees in token
     *        order -- so their queries batch together, as one page's do.
     *        Separate from $render, whose payload's root is the subtree
     *        itself, which a client from before groups still expects. Without
     *        it, a group request is a configuration error, not a refusal.
     * @param AuthContext|null $auth who is signed in, and at which auth epoch: every token
     *        minted -- a page's, /_bootstrap's -- is bound to them as a route token is, and the
     *        endpoint refuses one minted for anyone else. The Kernel fills in its own.
     */
    public function __construct(
        public Signer $signer,
        public BuildRegistry $builds,
        public \Closure $render,
        public \Closure $sessionId,
        public ?PanelContext $panels = null,
        public ?\Closure $allowedQueries = null,
        public ?Orm $orm = null,
        public ?\Closure $renderRoute = null,
        public ?Signer $routeSigner = null,
        public ?\Closure $renderGroup = null,
        public ?AuthContext $auth = null,
    ) {}

    /**
     * The minter for one page render.
     *
     * Built HERE rather than taken as another closure, because that is the
     * whole point: ADR 0009 promises the renderer cannot choose a token's
     * route, params, panel, build, session or expiry, and that is a property
     * of the framework only while the framework is what chooses them. An
     * application supplying its own mint closure could get any of them wrong
     * and hand the semi-trusted side exactly the control the ADR says it has
     * not got.
     *
     * @param array<string, string> $params
     * @param list<string>|null $components the components the route's
     *        boundaries name, from the fragment manifest; null for any
     */
    public function minterFor(
        Request $request,
        string $route,
        array $params,
        ?string $panel = null,
        ?string $tenant = null,
        ?array $components = null,
    ): FragmentMinter {
        // From the SAME source the endpoint compares against. It defaulted
        // to '', so every token minted as tenant-less whatever tenant the
        // request was in -- the read side and the write side disagreeing,
        // which is the second time that exact split has appeared here.
        // As a claim (TenantClaim), which is what the endpoint compares:
        // the tenant itself with tenancy registered, and with none a value
        // no tenant can be.
        $tenant = $this->orm === null ? $tenant ?? '' : TenantClaim::of($this->orm, $tenant ?? $this->orm->tenant());

        // The LIVE panel by default. It used to default to '', so the read
        // side consulted the context and the write side never did: every
        // statically-bootstrapped fragment minted a panel-less token whatever
        // panel its page was in.
        $panel ??= $this->panels?->currentOrNull() ?? '';

        return new FragmentMinter(
            signer: $this->signer,
            route: $route,
            params: $params,
            panel: $panel,
            tenant: $tenant,
            build: $this->builds->current,
            sessionId: ($this->sessionId)($request),
            components: $components,
            user: $this->auth?->id(),
            authEpoch: $this->auth?->epoch() ?? '',
        );
    }
}
