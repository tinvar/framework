<?php

declare(strict_types=1);

namespace Tinvar\Http;

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
     * @param \Closure(Request): string $sessionId must return a real session id; an empty
     *        one is refused, because every visitor without a session would otherwise share
     *        one binding and their tokens would be interchangeable
     */
    public function __construct(
        public Signer $signer,
        public BuildRegistry $builds,
        public \Closure $render,
        public \Closure $sessionId,
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
        string $panel = '',
    ): FragmentMinter {
        return new FragmentMinter(
            signer: $this->signer,
            route: $route,
            params: $params,
            panel: $panel,
            build: $this->builds->current,
            sessionId: ($this->sessionId)($request),
        );
    }
}
