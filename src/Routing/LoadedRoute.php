<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Data\Data;
use Tinvar\Orm\Model;

/** What the loaders produced for one request. */
final readonly class LoadedRoute
{
    /**
     * @param array<string, Data>  $layouts layout key => its data
     * @param array<string, Model> $models  route parameter name => bound model
     * @param array<string, string> $parameters
     * @param list<string>|null    $segments the segments a partial reload
     *        recomputed, outermost first, for the renderer to render alone;
     *        null for the whole page. Only those segments' data is here --
     *        `page` is null when the page was not one of them.
     */
    public function __construct(
        public RouteChain $chain,
        public ?Data $page,
        public array $layouts,
        public array $models,
        public array $parameters,
        public ?array $segments = null,
    ) {}

    public function isPartial(): bool
    {
        return $this->segments !== null;
    }
}
