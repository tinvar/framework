<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Actions\TagInvalidation;

/**
 * An endpoint answering POST: `#[Endpoint($path, methods: ['POST'])]`.
 *
 * It carries its session's CSRF token, as every POST does, unless it
 * declares #[WithoutSession] -- and then no session reaches it either. What
 * it writes it says it invalidates with `revalidates:`, as an action's
 * result does.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Post
{
    /**
     * @param list<string> $revalidates the tags it invalidates once its body has
     *        returned, as #[Endpoint]'s are (ADR 0075)
     */
    public function __construct(
        public string $path,
        public ?string $name = null,
        public array $revalidates = [],
    ) {
        TagInvalidation::declared("An endpoint at '{$path}'", $revalidates);
    }
}
