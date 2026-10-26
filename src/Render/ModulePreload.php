<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * The `Link` header that preloads a page's client modules, capped.
 *
 * tinvar-spec.md, "Operations and tooling": "Code splitting: one chunk per
 * client component; modulepreload for the current page's client references,
 * capped so Link headers never overflow server buffers."
 *
 * The renderer reports what the document's head preloads -- the client
 * entry, then the chunks of the client components the page rendered -- in a
 * `meta` frame, and this turns it into a header a proxy can act on before
 * the body arrives. A page with dozens of client components would write a
 * header past the 4 or 8 KB a reverse proxy buffers for all of a response's
 * headers -- nginx answers 502 when they do not fit -- so it stops at a
 * count and a byte budget, keeping the entries first in the head's order,
 * which are the ones the page needs first. The head's own <link> tags still
 * name every module.
 *
 * The renderer is the side that runs third-party npm code, so every entry
 * is checked to be a path on this origin, of characters a URL path is made
 * of, before it is written into a header.
 */
final class ModulePreload
{
    /** Entries in one header, at most. */
    public const int MAX_ENTRIES = 16;

    /** Bytes of header value, at most: half of the smallest common proxy buffer. */
    public const int MAX_BYTES = 2048;

    /** A path on this origin: no scheme, no host, nothing that could end the header or the link. */
    private const string PATH = '#^/(?!/)[A-Za-z0-9._~!$&\'()*+=:@%/-]{0,512}\z#';

    /**
     * The Link header for what a render's head preloads, or null when it
     * preloads nothing.
     */
    public static function header(RenderResult $result, int $maxEntries = self::MAX_ENTRIES, int $maxBytes = self::MAX_BYTES): ?string
    {
        $links = [];
        $bytes = 0;
        foreach (self::modulesOf($result->meta) as $href) {
            $link = "<{$href}>; rel=modulepreload";
            $added = \strlen($link) + ($links === [] ? 0 : 2);
            if (\count($links) >= $maxEntries || $bytes + $added > $maxBytes) {
                break;
            }
            $links[] = $link;
            $bytes += $added;
        }

        return $links === [] ? null : implode(', ', $links);
    }

    /**
     * The modules the renderer's meta frames name, in order, each once,
     * leaving out any that is not a path on this origin.
     *
     * @param list<array<string, mixed>> $meta
     * @return list<string>
     */
    public static function modulesOf(array $meta): array
    {
        // Kept by key, so each path is looked for once: read after the
        // render has returned, past its deadline, a list searched for every
        // path the renderer named took it a second and more to answer
        // the 29,000 a meta frame's 256 KB holds.
        $modules = [];
        foreach ($meta as $frame) {
            $preload = $frame['preload'] ?? null;
            if (!\is_array($preload)) {
                continue;
            }
            foreach ($preload as $href) {
                if (\is_string($href) && !isset($modules[$href]) && preg_match(self::PATH, $href) === 1) {
                    $modules[$href] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($modules));
    }
}
