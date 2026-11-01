<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy;

/** One file a recipe writes, relative to the application's root, with no secret in it. */
final readonly class RecipeFile
{
    public function __construct(
        public string $path,
        public string $contents,
    ) {}
}
