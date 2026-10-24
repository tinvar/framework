<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * What the client called a file, made fit to keep as metadata.
 *
 * "Client filenames are metadata only": the storage key never comes from
 * one. But the name is still shown -- beside a receipt, in a download's
 * Content-Disposition -- so it is cleaned of what would hurt there: any
 * directory part, as either kind of separator, so `../../etc/passwd` is
 * kept as `passwd`; control characters, which forge log lines and headers;
 * bytes that are not UTF-8; and length past what a filesystem would take.
 */
final class UploadName
{
    public const int MAX_BYTES = 255;

    /** What a file with no usable name is called. */
    public const string FALLBACK = 'upload';

    public static function clean(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');

        // The last segment under either separator: a browser sends a bare
        // name, but old ones and hand-made requests send a whole path.
        $name = (string) preg_replace('/^.*[\/\\\\]/su', '', $name);

        // Control characters, C1 included, and the bidirectional overrides
        // that make `gpj.exe` display as `exe.jpg`.
        $name = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name);
        $name = trim($name, " .\t");

        if (\strlen($name) > self::MAX_BYTES) {
            $name = mb_strcut($name, 0, self::MAX_BYTES, 'UTF-8');
        }

        return $name === '' ? self::FALLBACK : $name;
    }
}
