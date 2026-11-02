<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy;

/**
 * One platform's deployment (ADR 0156): the files that platform reads,
 * with the trusted-proxy ranges its edge connects from -- and a proxy
 * secret where its edge can send one -- the probes, the secrets it needs
 * in its secret store, and the layouts its containers allow.
 *
 * Pure: it writes text from a DeployApp, and `deploy:init` writes the text
 * to disk and generates the secrets. So a golden test holds every recipe
 * to its output.
 */
interface DeployRecipe
{
    public function target(): DeployTarget;

    /** @throws \InvalidArgumentException for a layout the platform cannot run, saying which it can */
    public function write(DeployApp $app): Recipe;
}
