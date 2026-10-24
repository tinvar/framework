<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * What the package codemods would change: a patch to review and apply by
 * hand, never applied for you.
 */
final readonly class CodemodResult
{
    /**
     * @param string $patch a unified diff against the app directory
     * @param list<string> $flagged `file:line: text` for each change touching authorization
     * @param list<string> $ran each codemod that ran, and how it ended
     * @param list<string> $failed each codemod that did not finish, and why
     */
    public function __construct(
        public string $patch,
        public array $flagged,
        public array $ran,
        public array $failed,
    ) {}

    public function needsReview(): bool
    {
        return $this->flagged !== [];
    }
}
