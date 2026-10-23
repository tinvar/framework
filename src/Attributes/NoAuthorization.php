<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * The declared escape hatch from the build rule that every model-typed input of
 * a routable entry point carries an #[Authorize] naming it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class NoAuthorization
{
    public function __construct(public string $reason) {}
}
