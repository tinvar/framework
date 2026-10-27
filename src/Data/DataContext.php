<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Http\Request;
use Tinvar\Routing\CompiledRoute;

/**
 * What a ProvidesData is told about where its value goes, and what a
 * #[Shared] method may take to know the same.
 */
final readonly class DataContext
{
    /**
     * @param string $key the shared key -- `customer` -- or, for a loader's field,
     *        the dot path a partial reload names it by: `page.summary`
     * @param CompiledRoute $page the page being rendered: its class, view and name
     * @param array<string, string> $parameters the page's route parameters, as
     *        matched: raw path segments, never models, so nothing here is
     *        anything the loaders have not already bound and authorized
     */
    public function __construct(
        public string $key,
        public Request $request,
        public CompiledRoute $page,
        public array $parameters = [],
    ) {}
}
