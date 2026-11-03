<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Container\Container;
use Tinvar\Resources\ResourceSearch;

/**
 * The search module's index, as an admin resource's search box asks it
 * (ADR 0152, ADR 0154): the keys of a #[Searchable] model's rows matching
 * what was typed, in the tenants the ORM's scope reads, which the list then
 * reads again through the ORM with its filters, order and page. A model
 * the search does not hold -- or no search at all -- is searched by the
 * resource's LIKE instead.
 */
final readonly class ResourceSearchIndex implements ResourceSearch
{
    public function __construct(private ?Search $search = null) {}

    /**
     * Has $container hand every resource this index over the Search the
     * application registers -- read when a resource is built, so a Search
     * registered after the Kernel is found -- or none, where it registers
     * none. What the Kernel calls.
     */
    public static function bind(Container $container): void
    {
        $container->bind(ResourceSearch::class, static fn (Container $c): ResourceSearch => new self(
            $c->registered(Search::class) ? $c->get(Search::class) : null,
        ));
    }

    public function searches(string $model): bool
    {
        return $this->search !== null && isset($this->search->models()[$model]);
    }

    public function keys(string $model, string $term, int $limit): array
    {
        if (!$this->searches($model)) {
            throw new \LogicException("{$model} is not searched by this index: ask searches() first.");
        }
        \assert($this->search !== null);

        return $this->search->for($model)->query($term)->keys($limit);
    }
}
