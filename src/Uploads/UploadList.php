<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * The files an action is handed for an `UploadList` parameter -- one
 * declared `#[Uploaded(maxFiles: N)]` -- each an Upload held to everything
 * its ticket bound it to, as a single one is, and all claimed for this call
 * or none.
 *
 * In the order the form sent their references, none twice, at most the
 * declared number.
 *
 * @implements \IteratorAggregate<int, Upload>
 */
final readonly class UploadList implements \IteratorAggregate, \Countable
{
    /** @param list<Upload> $files */
    public function __construct(public array $files = []) {}

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->files);
    }

    public function count(): int
    {
        return \count($this->files);
    }
}
