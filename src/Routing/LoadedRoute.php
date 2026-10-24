<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Data\PayloadContext;
use Tinvar\Orm\Model;

/** What the loaders produced for one request. */
final readonly class LoadedRoute
{
    /**
     * @param array<string, Data>  $layouts layout key => its data
     * @param array<string, Model> $models  route parameter name => bound model
     * @param array<string, string> $parameters
     * @param list<string>|null    $segments the segments a partial reload
     *        re-renders, outermost first, for the renderer to render alone;
     *        null for the whole page. Only those segments' data is here --
     *        `page` is null when the page was not one of them.
     * @param PayloadContext $context what the request says the browser holds
     *        -- the once keys it keeps -- for encode() (ADR 0020)
     */
    public function __construct(
        public RouteChain $chain,
        public ?Data $page,
        public array $layouts,
        public array $models,
        public array $parameters,
        public ?array $segments = null,
        public PayloadContext $context = new PayloadContext(),
    ) {}

    public function isPartial(): bool
    {
        return $this->segments !== null;
    }

    public function withContext(PayloadContext $context): self
    {
        return new self(
            $this->chain,
            $this->page,
            $this->layouts,
            $this->models,
            $this->parameters,
            $this->segments,
            $context,
        );
    }

    /**
     * The loader data as the renderer is handed it, with what the request
     * said the browser holds applied to every segment's.
     *
     * One place for it, so an application cannot encode the page with the
     * request's context and a layout without.
     *
     * @return array{page: mixed, layouts: array<string, mixed>}
     */
    public function encode(Encoder $encoder): array
    {
        $layouts = [];
        foreach ($this->layouts as $key => $data) {
            $layouts[$key] = $encoder->encode($data, $this->context);
        }

        return [
            'page' => $encoder->encode($this->page, $this->context),
            'layouts' => $layouts,
        ];
    }
}
