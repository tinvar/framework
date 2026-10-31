<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * What signs a <Deferred> boundary's token for one render: a page render's
 * FragmentMinter, or a static build's StaticMinter.
 *
 * What it counts -- the tokens one render may mint, the boundaries a build
 * records -- is one render's. A render RenderChannel tries again (ADR 0093)
 * is a new render, so it calls reset() before each try after the first:
 * the try that failed must not leave the next with its spent budget, or
 * with boundaries the page it finally draws does not have.
 */
interface Minter
{
    /**
     * @param array<string, mixed> $props
     * @throws FragmentTokenError for one boundary refused, the render going on
     */
    public function mint(string $component, array $props): string;

    /** Forgets what earlier tries of this render minted. */
    public function reset(): void;
}
