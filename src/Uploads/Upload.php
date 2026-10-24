<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * A file an action is handed for an `Upload` parameter: stored, sniffed and
 * verified against everything its ticket bound it to, and claimed for this
 * call alone.
 *
 * The key is the server's, and is what an action keeps on its model. The
 * name is what the client called the file, cleaned (UploadName) and kept as
 * metadata only -- something to show beside the file, never a path.
 */
final readonly class Upload
{
    public function __construct(
        public string $id,
        public string $key,
        public string $name,
        public string $type,
        public int $size,
    ) {}
}
