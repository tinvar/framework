<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Orm\Orm;

/**
 * The tags a page's loaders read, and which segment read each.
 *
 * A query declares its tags on its attribute and QueryRunner records them as
 * it runs; a loader reads its data itself, so it says what it read here:
 *
 *     public function __invoke(): OrdersPageData
 *     {
 *         $this->tags->read("orders:customer:{$id}");
 *         ...
 *
 * What a render read is what its live subscription may listen to (see
 * LiveMinter), so a loader that reads data some write invalidates names the
 * tag that write names, or its page is never told.
 *
 * Kept by segment -- a layout's key, or `page` -- because a layout's data
 * "reloads only when its params change, a tag it read is revalidated, or it
 * is named in X-Only" (tinvar-spec.md, "Layout loaders"): a tag only one
 * segment's loader read is refreshed by a partial reload of that segment.
 * LoaderRunner says which segment is running.
 *
 * Request-scoped, and cleared by the reset contract: one request's tags in
 * the next request's token would subscribe a page to what it never read.
 */
final class TagsRead
{
    /** The segment of a tag read outside any loader: the render as a whole. */
    public const string WHOLE = '*';

    /** @var array<string, array<string, true>> namespaced tag => segments that read it */
    private array $read = [];

    private ?string $segment = null;

    public function __construct(private readonly Orm $orm) {}

    /**
     * Records tags the code running now has read, in the tenant it runs as
     * -- the namespace QueryCache keeps the same tags under.
     */
    public function read(string ...$tags): void
    {
        $tenant = $this->orm->tenant();
        foreach ($tags as $tag) {
            if ($tag === '') {
                continue;
            }
            $this->read[$tenant . "\0" . $tag][$this->segment ?? self::WHOLE] = true;
        }
    }

    /**
     * Runs a segment's loader, attributing what it reads to that segment.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function within(string $segment, \Closure $work): mixed
    {
        $outer = $this->segment;
        $this->segment = $segment;
        try {
            return $work();
        } finally {
            $this->segment = $outer;
        }
    }

    /** @return array<string, list<string>> namespaced tag => the segments that read it */
    public function all(): array
    {
        // As strings: PHP turns a numeric string key into an int.
        return array_map(
            static fn (array $segments): array => array_map(strval(...), array_keys($segments)),
            $this->read,
        );
    }

    public function forget(): void
    {
        $this->read = [];
        $this->segment = null;
    }
}
