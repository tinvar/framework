<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * What looks inside an uploaded file for malware before it is kept: the
 * seam a virus scanner plugs into (ClamdScanner, or the application's own).
 *
 * Asked by /_upload of every file, after its type is sniffed and an image
 * drawn again -- so of the bytes that would be kept -- and before anything
 * is stored. A file found infected is refused; a scanner that cannot
 * answer refuses the upload too. Never kept unscanned: a scanner the
 * application configured and cannot reach is not one to fail open past.
 */
interface FileScanner
{
    /**
     * @return string|null the name of what was found, or null for a clean file
     * @throws ScanFailed when the file could not be scanned
     */
    public function scan(string $path): ?string;
}
