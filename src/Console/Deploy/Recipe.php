<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy;

/** What a recipe writes for one application: its files, the secrets it needs, and the steps that follow. */
final readonly class Recipe
{
    /**
     * @param list<RecipeFile> $files
     * @param list<PlatformSecret> $secrets
     * @param list<string> $steps each a command, or a `# comment` saying what the next does
     */
    public function __construct(
        public array $files,
        public array $secrets,
        public array $steps,
    ) {}
}
