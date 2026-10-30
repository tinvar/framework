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
 * A subtree rendered as an error row in a render that went on -- a Suspense
 * boundary that threw after the shell, a fragment -- is one too, marked
 * `boundary`: the visitor has the page, and the boundary its error state.
 *
 * The Kernel hands it to the closures it was given, in order, and then to the
 * application's event bus, where a #[Listener] method taking
 * a RenderFailed hears it -- all before the error page is chosen. Never
 * queued: it carries the exception and the request, which do not travel.
 */
final readonly class RenderFailed
{
    /**
     * @param RendererException|\Tinvar\Protocol\ProtocolException $exception
     * @param string $digest the reference the visitor is shown, and the log line is under
     * @param bool $boundary whether it was one subtree -- a Suspense boundary's in a page,
     *        or a fragment's -- that the renderer rendered as an error row in a render that
     *        went on (ADR 0112): the response is still the page, the boundary its error
     *        state, and the digest the one on that row. False for a render that failed whole
     */
    public function __construct(
        public \Throwable $exception,
        public RenderFailure $failure,
        public Request $request,
        public string $digest,
        public bool $boundary = false,
    ) {}
}
