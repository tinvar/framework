<?php

declare(strict_types=1);

namespace Tinvar\Http;

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
}
