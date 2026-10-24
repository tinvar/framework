<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Where a session's previous URL is kept between its requests.
 *
 * tinvar-spec.md, "Security": "Previous URL: track it for client visits
 * (Inertia's Laravel adapter v3.4.0+), excluding prefetches and partial
 * requests." What the page before this one was is a fact about the session,
 * and nothing in one request outlives it but the session.
 *
 * The session is the application's: the framework has no session layer, and
 * the shop keeps its own server-side. So the store is too, as FlashStore is.
 * An implementation keys what it keeps by the request's session, keeps one
 * URL per session -- each visit replaces the last -- and forgets it when the
 * session ends. The Kernel decides which requests are visits and calls put()
 * only for those (see PreviousUrl), so a store records whatever it is given.
 */
interface PreviousUrlStore
{
    /**
     * The URL the request's session last visited -- a path and query on this
     * origin -- or null when it has none, or when the request has no session.
     */
    public function get(Request $request): ?string;

    /** Keeps a URL as the request's session's last visit, replacing any it held. */
    public function put(Request $request, string $url): void;
}
