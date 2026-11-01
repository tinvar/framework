<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;

/**
 * Which resource a screen is of, and the way to its list and its create
 * form. Paths on the host the screen is on, as the resource's pages are.
 */
final readonly class ResourceSummaryData extends Data
{
    /**
     * @param string $name the routes' middle part: `products` in `admin.products.index`
     * @param string|null $createUrl the create form, where the reader's policy lets them create
     *        one -- which decides only whether the link is drawn: the form and the action ask again
     */
    public function __construct(
        public string $name,
        public string $singular,
        public string $plural,
        public string $indexUrl,
        public ?string $createUrl,
    ) {}
}
