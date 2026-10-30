<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * What a `Link` header may name of the build's assets, and with which
 * digest: the preloads a page's response carries, and the 103 Early Hints
 * sent before it renders (ADR 0108).
 *
 * A preload a header names is fetched before the document's import map is
 * read. Chromium puts a module preloaded that way in its module map as it
 * came, and a later import of it never asks the map for its digest -- so a
 * build held to its digests (ADR 0097) sent no header at all. It sends one
 * again with each entry's digest on it, `integrity="sha384-..."`, which
 * Chromium holds a header's modulepreload and an Early Hint to.
 *
 * The digests are the build's, read from `rsc/assets.json`, never the
 * renderer's: the renderer runs npm code, and only names which modules a
 * page loaded. So an entry is written only when it is a path the build
 * writes on this origin, or on the CDN origin the build was made for --
 * and, in a build with digests, only with its digest. One the build has no
 * digest for is left out rather than sent unchecked: that is how a CDN
 * build without them sends no CDN entry at all.
 */
final readonly class AssetLinks
{
    /** A path on this origin: no scheme, no host, nothing that could end the header or the link. */
    private const string PATH = '#^/(?!/)[A-Za-z0-9._~!$&\'()*+=:@%/-]{0,512}\z#';

    /** A digest as the build writes it. */
    private const string DIGEST = '#^sha384-[A-Za-z0-9+/]{64}\z#';

    /**
     * @param array<string, string> $integrity each asset's URL => its digest
     * @param list<string>          $modules   what every page loads first: the client entry and its imports
     * @param list<string>          $styles    the stylesheets every page links
     */
    public function __construct(
        public ?string $origin = null,
        public array $integrity = [],
        public array $modules = [],
        public array $styles = [],
    ) {}

    /** Paths on this origin, unchecked: a build that records nothing, or none at all. */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Read from the build's asset record, each part held to its shape: a
     * digest that is not one, or a hint that is not a URL this could name,
     * is dropped.
     */
    public static function fromBuild(BuiltAssets $assets): self
    {
        $record = $assets->record();
        $origin = $assets->origin();

        $integrity = [];
        foreach (\is_array($record['integrity'] ?? null) ? $record['integrity'] : [] as $url => $digest) {
            if (\is_string($url) && \is_string($digest) && preg_match(self::DIGEST, $digest) === 1) {
                $integrity[$url] = $digest;
            }
        }
        $links = new self($origin, $integrity);

        $hints = \is_array($record['hints'] ?? null) ? $record['hints'] : [];
        $list = static fn (mixed $urls): array => array_values(array_filter(
            \is_array($urls) ? $urls : [],
            static fn (mixed $url): bool => \is_string($url) && $links->accepts($url),
        ));

        return new self($origin, $integrity, $list($hints['modules'] ?? null), $list($hints['styles'] ?? null));
    }

    /**
     * Whether $href may go in a header: a path on this origin or on the
     * build's CDN, and, where the build has digests, one of them.
     */
    public function accepts(string $href): bool
    {
        $path = $href;
        if ($this->origin !== null && str_starts_with($href, $this->origin . '/')) {
            $path = substr($href, \strlen($this->origin));
        } elseif ($this->origin !== null && !str_starts_with($href, '/')) {
            return false;
        }
        if (preg_match(self::PATH, $path) !== 1) {
            return false;
        }
        // An asset on the CDN is another party's bytes: never named without
        // its digest. On this origin, only where the build has digests.
        if ($this->integrity !== [] || $path !== $href) {
            return isset($this->integrity[$href]);
        }

        return true;
    }

    /**
     * One entry of a Link header for $href, or null when it may not be in one.
     *
     * @param 'modulepreload'|'style' $kind a module, or a stylesheet to preload
     */
    public function link(string $href, string $kind = 'modulepreload'): ?string
    {
        if (!$this->accepts($href)) {
            return null;
        }
        // As the page's own <link> asks for it, so the browser uses what
        // the header fetched: the stylesheet's is crossorigin="anonymous".
        $link = $kind === 'style' ? "<{$href}>; rel=preload; as=style; crossorigin" : "<{$href}>; rel=modulepreload";

        return isset($this->integrity[$href]) ? "{$link}; integrity=\"{$this->integrity[$href]}\"" : $link;
    }

    /**
     * The Link header a 103 Early Hint carries -- the stylesheets every
     * page links, then the client entry and what it imports -- capped as a
     * page's own preload header is, or null when there is nothing to hint.
     */
    public function earlyHints(int $maxEntries = 16, int $maxBytes = 2048): ?string
    {
        $entries = [];
        foreach ($this->styles as $href) {
            $entries[] = $this->link($href, 'style');
        }
        foreach ($this->modules as $href) {
            $entries[] = $this->link($href);
        }

        return self::capped(array_values(array_filter($entries, is_string(...))), $maxEntries, $maxBytes);
    }

    /**
     * $entries joined into one header value, stopping at $maxEntries or
     * $maxBytes, whichever comes first; null for none.
     *
     * @param list<string> $entries
     */
    public static function capped(array $entries, int $maxEntries, int $maxBytes): ?string
    {
        $kept = [];
        $bytes = 0;
        foreach ($entries as $entry) {
            $added = \strlen($entry) + ($kept === [] ? 0 : 2);
            if (\count($kept) >= $maxEntries || $bytes + $added > $maxBytes) {
                break;
            }
            $kept[] = $entry;
            $bytes += $added;
        }

        return $kept === [] ? null : implode(', ', $kept);
    }
}
