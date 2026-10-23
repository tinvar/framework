<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Auth\PanelContext;
use Tinvar\Orm\Orm;
use Tinvar\Render\FragmentMinter;
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
     * @param \Closure(\Tinvar\Render\FragmentToken, Request, array<string, mixed>): Response $render
     * @param (\Closure(string): list<string>)|null $allowedQueries the originating
     *        route's query allowlist, by route name. The ENDPOINT asks for
     *        this and hands the answer to $render, rather than letting the
     *        render closure choose: "PHP rejects any other name on that
     *        route's socket" is only true while the list comes from the
     *        route. A closure free to pass the whole registry would make
     *        /_fragment a query endpoint with no allowlist, no CSRF, no
     *        authentication and the wrong throttle bucket.
     * @param \Closure(Request): string $sessionId must return a real session id; an empty
     *        one is refused, because every visitor without a session would otherwise share
     *        one binding and their tokens would be interchangeable
     */
    public function __construct(
        public Signer $signer,
        public BuildRegistry $builds,
        public \Closure $render,
        public \Closure $sessionId,
        public ?PanelContext $panels = null,
        public ?\Closure $allowedQueries = null,
        public ?Orm $orm = null,
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
     */
    public function minterFor(
        Request $request,
        string $route,
        array $params,
        ?string $panel = null,
        ?string $tenant = null,
    ): FragmentMinter {
        // From the SAME source the endpoint compares against. It defaulted
        // to '', so every token minted as tenant-less whatever tenant the
        // request was in -- the read side and the write side disagreeing,
        // which is the second time that exact split has appeared here.
        $tenant ??= $this->orm?->tenant() ?? '';

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
        );
    }
}
