<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Where a flash waits for the next page: kept per session, read once.
 *
 * tinvar-spec.md, "Forms and mutations": "Without JavaScript, fall back to
 * redirect-back with flashed errors." A browser's own form post cannot be
 * answered with field errors in place -- it is a navigation, and its answer
 * is a page -- so the errors have to outlive the redirect, and nothing in
 * one request outlives it but the session.
 *
 * The session is the application's: the framework has no session layer, and
 * the shop keeps its own server-side. So the store is too. An implementation
 * keys what it keeps by the request's session, replaces what that session
 * held before -- one flash per session, so a burst of posts cannot pile them
 * up -- and removes it as it is read, so a reload does not show the same
 * errors twice.
 */
interface FlashStore
{
    /**
     * Keeps a flash for the request's session, replacing any it held.
     *
     * @param array<string, mixed> $flash
     */
    public function put(Request $request, array $flash): void;

    /**
     * The request's session's flash, removed as it is read; [] when it has
     * none, or when it has waited longer than Flash::LIFETIME.
     *
     * Asked whenever a document load carries Flash::COOKIE, which anyone can
     * send with every page view: with nothing waiting it must write nothing
     * -- on SQLite, a DELETE that matches no row still takes the write lock.
     *
     * @return array<string, mixed>
     */
    public function pull(Request $request): array;
}
