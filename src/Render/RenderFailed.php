<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Http\Request;

/**
 * A render failed: the renderer threw, timed out, could not be reached, or
 * broke the protocol, while answering this request.
 *
 * tinvar-spec.md, "Operations and tooling": "Fire a RenderFailed event, and
 * add throw_on_error for tests." Inertia falls back to client rendering at
 * this point, which RSC cannot do -- a server component has no client
 * version -- so the request is answered with an error page instead, and this
 * is what an application hears about it: to count failures by kind, to page
 * someone when the renderer is unreachable, to fail a test.
 *
 * The framework has no event bus, so the Kernel hands it to the listeners it
 * was given, in order, before the error page is chosen.
 */
final readonly class RenderFailed
{
    /**
     * @param RendererException|\Tinvar\Protocol\ProtocolException $exception
     * @param string $digest the reference the visitor is shown, and the log line is under
     */
    public function __construct(
        public \Throwable $exception,
        public RenderFailure $failure,
        public Request $request,
        public string $digest,
    ) {}
}
