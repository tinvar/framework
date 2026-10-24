<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * What one encode() is for: the request's word on what the browser holds,
 * and the route segment the value is the data of.
 *
 * @internal the Encoder's, passed down its walk
 */
final class EncodeScope
{
    /** The part of a default once key that names the page, worked out the first time one is needed. */
    private ?string $page = null;

    /**
     * Data classes met so far whose once keys name no subject of their own,
     * with the path each was first met at. Such a class may be met once:
     * a second instance would be kept under the same key as the first.
     *
     * @var array<class-string, string>
     */
    private array $unnamed = [];

    public function __construct(
        public readonly PayloadContext $context,
        public readonly string $segment,
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

    /**
     * What a once field's default key is scoped to: the page the request
     * asked for and the segment the field is in. Two customers' pages, two
     * pages of a list, a layout and the page inside it: each its own.
     */
    public function page(): string
    {
        return $this->page ??= PayloadContext::digest($this->segment . "\0" . $this->context->route);
    }

    /**
     * Notes a Data class whose once keys name no subject of their own, and
     * refuses it the second time: the rows of a list, or one class in two
     * places, would all be kept under one key, and the browser would put
     * one's content back in another's place.
     *
     * @param class-string $class
     */
    public function meetUnnamed(string $class, string $field, string $key, string $path): void
    {
        if (isset($this->unnamed[$class])) {
            throw new \LogicException(
                "{$class}::\${$field} is #[Once] under the key '{$key}', which names no subject of its own, "
                . "and {$class} is met twice in one payload: at {$this->unnamed[$class]} and at {$path}. "
                . "Each would be kept under that one key. Name the subject in the key -- #[Once(key: 'row.badge:{id}')] -- "
                . 'from a property of the class.',
            );
        }
        $this->unnamed[$class] = $path;
    }
}
