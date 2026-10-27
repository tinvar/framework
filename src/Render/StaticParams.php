<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * The params of every page a #[StaticPage(from: ...)] route builds, when
 * they are not known when the attribute is written: the pages of a
 * directory of content, say, each a file whose name is its param.
 *
 * Made with no arguments and asked once per build, by the builder, outside
 * any request: it reads what the build reads -- files in the app's own tree
 * -- and nothing of any visitor's. What it returns is held to the same
 * shape as a param the attribute lists: each value names a directory, so
 * nothing that could be escaped in a URL or climb out of one is built.
 */
interface StaticParams
{
    /**
     * @return list<array<string, string|int>> one set of params per page to build
     */
    public function params(): array;
}
