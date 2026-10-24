<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Attributes\ErrorPage;
use Tinvar\Http\Request;
use Tinvar\Routing\LoadedRoute;

/**
 * One route the Kernel asked to have rendered during a page test: what the
 * renderer would have been handed, and the request as it reached it.
 */
final readonly class RecordedRender
{
    /**
     * @param array{page: mixed, layouts: array<string, mixed>} $encoded the loader data
     *        as the renderer is handed it, encoded inside the request
     * @param int $statements statements the request had issued by then: its middleware,
     *        checks and loaders
     */
    public function __construct(
        public LoadedRoute $loaded,
        public Request $request,
        public array $encoded,
        public int $statements,
    ) {}

    /** Whether this was the #[ErrorPage] a failed document request is answered with. */
    public function isErrorPage(): bool
    {
        return (new \ReflectionClass($this->loaded->chain->page->class))->getAttributes(ErrorPage::class) !== [];
    }
}
