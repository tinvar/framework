<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The URL a session visited before the request it is read on.
 *
 * tinvar-spec.md, "Security": "Previous URL: track it for client visits
 * (Inertia's Laravel adapter v3.4.0+), excluding prefetches and partial
 * requests." A loader building a "back" link, or an action sending its
 * caller back where it came from, reads it with Request::previousUrl().
 *
 * Read when first asked, and once. Most requests never ask, and the store is
 * the application's session: a read on every request would be a database
 * read for an answer nobody wanted. The Kernel attaches one to every request
 * and reads it itself only for a visit it is about to record -- before
 * recording it -- so whatever reads it, before or after, is told the visit
 * before this one, never this one.
 */
final class PreviousUrl
{
    /** The request attribute carrying it; see Request::previousUrl(). */
    public const string ATTRIBUTE = 'tinvar.previous_url';

    /**
     * The longest URL recorded, in bytes. A session store is not where an
     * arbitrarily long query string belongs, and a cut-down one would send
     * someone back somewhere they never were; past this, a visit leaves the
     * previous URL as it was.
     */
    public const int MAX_BYTES = 2048;

    private bool $known = false;
    private ?string $url = null;

    /** @param \Closure(): ?string $read asks the store */
    public function __construct(private readonly \Closure $read) {}

    /** The previous URL, or null when the session has none. */
    public function url(): ?string
    {
        if (!$this->known) {
            $this->known = true;
            $url = ($this->read)();

            // The store is the application's, and what it hands back is used
            // as a place to send people: only a path on this origin counts,
            // however it came to be stored.
            $this->url = \is_string($url) && Response::isSameOriginTarget($url) ? $url : null;
        }

        return $this->url;
    }

    /**
     * Whether a page request is a visit, and so moves the previous URL on: a
     * document load or a client visit that rendered a page.
     *
     * Not a prefetch -- "Prefetch requests skip side effects like
     * previous-URL tracking": a pointer resting on a link is not a visit. Not
     * a partial reload (`X-Only` / `X-Except`), nor a background request
     * (`X-Background`), which re-ask for the page already up: a poll that
     * moved it would make the page someone left a minute ago the one "back"
     * goes to from a page they opened since in another tab. And only a page
     * that rendered: a redirect is not somewhere anyone stayed, and an error
     * page is not somewhere to go back to.
     */
    public static function records(Request $request, Response $response): bool
    {
        return $request->method === 'GET'
            && $response->status >= 200 && $response->status < 300
            && !$request->isPrefetch()
            && !$request->isBackground()
            && !$request->hasHeader('x-only')
            && !$request->hasHeader('x-except');
    }

    /** What a visit records: the path and query, as the browser asked for them. */
    public static function of(Request $request): string
    {
        return $request->rawQuery === null || $request->rawQuery === ''
            ? $request->path
            : "{$request->path}?{$request->rawQuery}";
    }
}
