<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * What one encode() is for: the request's word on what the browser holds,
 * and the route segment the value is the data of.
 *
 * @internal the Encoder's, passed down its walk
 */
final readonly class EncodeScope
{
    public function __construct(
        public PayloadContext $context,
        public string $segment,
    ) {}

    /**
     * The dot path the browser knows a field by -- `page.orders`, as X-Only
     * and X-Reset name it -- from the encoder's own `$.orders` path, whose
     * root stands for the segment and whose list positions are `[i]`.
     */
    public function dotPath(string $path): string
    {
        return $this->segment . (string) preg_replace('/\[(\d+)\]/', '.$1', substr($path, 1));
    }
}
