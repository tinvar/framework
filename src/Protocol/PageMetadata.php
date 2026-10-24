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
    ) {}

    /** @return array{url: string, version: string, routeToken: string|null, view: string|null} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'version' => $this->version, 'routeToken' => $this->routeToken, 'view' => $this->view];
    }
}
