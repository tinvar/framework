<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Cache\Duration;

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
 *
 * Revalidated in place, page by page (StaticPages): `revalidate` rebuilds a
 * page once its file is older than that -- `tinvar static:revalidate`, or
 * the RevalidateStaticPages task each minute -- and `tags` takes it out of
 * service the moment an action outside any tenant invalidates one of them,
 * so PHP serves it, fresh, until it is built again. Neither ever builds it
 * as anyone but nobody.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class StaticPage
{
    /** Its revalidate interval in seconds, or null to keep it until a build or a tag replaces it. */
    public ?int $revalidateAfter;

    /**
     * @param list<array<string, string|int>> $params the params of each page to
     *        build from the route; one page, with none, by default
     * @param string|null $revalidate how long a built page stands before it is
     *        built again: '30s', '10m', '1h'
     * @param list<string> $tags what it shows, by the tags actions invalidate
     */
    public function __construct(
        public array $params = [[]],
        ?string $revalidate = null,
        public array $tags = [],
    ) {
        $this->revalidateAfter = $revalidate === null ? null : Duration::seconds($revalidate);

        foreach ($tags as $tag) {
            if ($tag === '') {
                throw new \InvalidArgumentException('A static page\'s tags are non-empty strings.');
            }
        }
    }
}
