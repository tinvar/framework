<?php

declare(strict_types=1);

namespace Tinvar\Protocol;

/**
 * What the client router needs to know about the page it starts on.
 *
 * tinvar-spec.md, "Protocol": "The initial page metadata goes in a
 * `<script type="application/json">` element". A client navigation reads
 * these from response headers; a document load cannot read headers at all,
 * so without this the first page had no route token -- an action taken on
 * it could never revalidate it -- and the client had no way to learn its
 * own build, which it needs for X-Version.
 *
 * Plain strings only. The renderer writes them into the HTML and owns the
 * escaping, since it is the one producing the markup.
 */
final readonly class PageMetadata
{
    public function __construct(
        /** The page's URL: path and query. */
        public string $url,
        /** The build the page was rendered for, sent back as X-Version. */
        public string $version,
        public ?string $routeToken = null,
        /**
         * The page's view, sent back as X-Page with a partial reload, which
         * "applies only to the same page". Null where partial reloads are
         * off, and the first page cannot ask for part of itself.
         */
        public ?string $view = null,
        /**
         * The session's history key, for a page whose history is encrypted
         * (RequestAttributes::HISTORY_KEY, ADR 0019); null for one whose is
         * not.
         */
        public ?string $historyKey = null,
        /**
         * What the page's own reports -- its vitals, its errors -- are
         * filed under (ADR 0129): its route's name, or the view of a page
         * with none. A route token carries the name too, but a page with
         * no session has no token -- a static page -- and an unnamed page
         * has no name. Nothing new is said by it: the name is in the token
         * a session's page is given, and the view is the page's own, which
         * a payload's X-Page names.
         */
        public ?string $route = null,
    ) {}

    /**
     * Absent rather than null, the history key and the route: a page with
     * nothing to encrypt and nothing to file its reports under sends what
     * it always sent.
     *
     * @return array{url: string, version: string, routeToken: string|null, view: string|null, historyKey?: string, route?: string}
     */
    public function toArray(): array
    {
        $page = ['url' => $this->url, 'version' => $this->version, 'routeToken' => $this->routeToken, 'view' => $this->view];
        if ($this->historyKey !== null) {
            $page['historyKey'] = $this->historyKey;
        }
        if ($this->route !== null) {
            $page['route'] = $this->route;
        }

        return $page;
    }
}
