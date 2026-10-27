<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * An endpoint answering POST: `#[Endpoint($path, methods: ['POST'])]`.
 *
 * It carries its session's CSRF token, as every POST does, unless it
 * declares #[WithoutSession] -- and then no session reaches it either.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Post
{
    public function __construct(
        public string $path,
        public ?string $name = null,
    ) {}
}
