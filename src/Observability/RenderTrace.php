<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/** One render as Telemetry follows it, from renderStarted() to its end. */
final readonly class RenderTrace
{
    /** @param string $kind document, payload, fragment or mail */
    public function __construct(
        public string $kind,
        public ?Span $span,
    ) {}
}
