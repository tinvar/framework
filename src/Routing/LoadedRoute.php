<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Data\Data;
use Tinvar\Data\DataContext;
use Tinvar\Data\Encoder;
use Tinvar\Data\PayloadContext;
use Tinvar\Features\FlagReads;
use Tinvar\Http\Request;
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
     *        -- once keys, merged fields to reset -- for encode() (ADR 0020)
     * @param array<string, true> $flagged the segments whose loader read a
     *        feature flag: their #[Once] fields are sent not to be kept
     */
    public function __construct(
        public RouteChain $chain,
        public ?Data $page,
        public array $layouts,
        public array $models,
        public array $parameters,
        public ?array $segments = null,
        public PayloadContext $context = new PayloadContext(),
        public array $flagged = [],
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
            $this->flagged,
        );
    }

    /**
     * The loader data as the renderer is handed it: each segment's encoded
     * under its own key, so a merged field is known by the dot path a
     * client names it by, with what the request said it holds applied.
     *
     * One place for it, so an application cannot encode the page with the
     * request's context and a layout without, or root a layout's paths at
     * `page`.
     *
     * Given the request, a ProvidesData among the fields becomes its Data
     * told its dot path, the request and this page (ADR 0060); without it,
     * one is refused.
     *
     * @return array{page: mixed, layouts: array<string, mixed>}
     */
    public function encode(Encoder $encoder, ?Request $request = null): array
    {
        $provide = $request === null
            ? null
            : fn (string $key): DataContext => new DataContext($key, $request, $this->chain->page, $this->parameters);

        // A segment whose loader read a feature flag, or whose encoding did
        // -- a Lazy field is the loader's work deferred -- sends its #[Once]
        // fields to be shown and not kept: a hold is believed until it
        // expires, and a flag is not among what it is bound to (ADR 0094).
        $segment = function (?Data $data, string $key) use ($encoder, $provide): mixed {
            $reads = new FlagReads();
            $encoded = $reads->span(fn (): mixed => $encoder->encode($data, $this->context, $key, $provide));

            return $reads->clean() && !isset($this->flagged[$key]) ? $encoded : self::unkept($encoded);
        };

        $layouts = [];
        foreach ($this->layouts as $key => $data) {
            $layouts[$key] = $segment($data, $key);
        }

        return [
            'page' => $segment($this->page, PartialReload::PAGE),
            'layouts' => $layouts,
        ];
    }

    /** $encoded with every #[Once] field in it, at any depth, marked not to be kept. */
    private static function unkept(mixed $encoded): mixed
    {
        if (!\is_array($encoded)) {
            return $encoded;
        }
        foreach ($encoded as $name => $value) {
            $encoded[$name] = self::unkept($value);
        }
        if (isset($encoded[Encoder::ONCE_KEY]) && \array_key_exists('value', $encoded)) {
            $encoded['keep'] = false;
        }

        return $encoded;
    }
}
