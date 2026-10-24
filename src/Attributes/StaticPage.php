<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A page `tinvar build:static` renders to a file at build time, for a server
 * to send without PHP rendering it.
 *
 * tinvar-spec.md: "Fragments inside static pages get their tokens from a
 * small per-visitor bootstrap request, never from the build", and "stored
 * output (static, revalidated) never contains a CSRF token". So a static page
 * is rendered as nobody: no session, no user, through the page's whole
 * request path -- middleware, loaders, #[Authorize] -- and a page that would
 * not be shown to a visitor with no session fails the build. Whatever is the
 * visitor's own reaches them afterwards: fragment tokens from /_bootstrap,
 * the CSRF token from the cookie their first request to PHP is given.
 *
 * Read off the page class itself, as EncryptHistory is. A page that asks for
 * its history encrypted cannot be built: its key is the session's.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class StaticPage
{
    /**
     * @param list<array<string, string|int>> $params the params of each page to
     *        build from the route; one page, with none, by default
     */
    public function __construct(public array $params = [[]]) {}
}
