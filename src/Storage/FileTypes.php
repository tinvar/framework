<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * What an uploaded file is, from its bytes -- never from its name or from
 * the type the client sent, both of which the client chose.
 *
 * A short list, on purpose: the types a form plausibly takes, each with a
 * signature that cannot be mistaken for another, and none that a browser
 * runs. No SVG, no HTML, no XML -- each is a document that can carry
 * script, and an upload served back as one would be stored cross-site
 * scripting on this origin. Anything that sniffs as none of these is
 * refused.
 *
 * Its own table rather than libmagic's: fileinfo's answer depends on the
 * magic database the machine has, which differs between servers and
 * versions, and a type check that passes on one machine and not another is
 * not a check.
 */
final class FileTypes
{
    /** Each type recognised, and the extension a key for it ends in. */
    public const array EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** How much of a file is read to sniff it. */
    public const int HEAD_BYTES = 16;

    public static function known(string $type): bool
    {
        return isset(self::EXTENSIONS[$type]);
    }

    public static function extension(string $type): string
    {
        return self::EXTENSIONS[$type] ?? throw new \InvalidArgumentException("'{$type}' is not a type uploads recognise.");
    }

    /** Whether a type is an image a browser may show inline. */
    public static function isImage(string $type): bool
    {
        return self::known($type) && str_starts_with($type, 'image/');
    }

    /**
     * The type the first bytes of a file say it is, or null for none of
     * the recognised ones.
     *
     * Past the bare signature where the format allows: a PNG's must be
     * followed by its IHDR chunk, a WebP's RIFF container must hold a VP8
     * stream. Eight bytes anyone can type are not a format.
     */
    public static function sniff(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' && substr($head, 12, 3) === 'VP8' => 'image/webp',
            str_starts_with($head, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    /**
     * The type of the file at $path, or null.
     *
     * An image is also read as one -- its header, never its pixels, so a
     * small file claiming to be enormous costs nothing here: a header
     * PHP's image reader cannot make sense of, that reads as another
     * format, or that gives the image no size, is a file that only begins
     * like an image.
     */
    public static function sniffFile(string $path): ?string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }
        $head = fread($stream, self::HEAD_BYTES);
        fclose($stream);

        $type = self::sniff($head === false ? '' : $head);
        if ($type === null || !self::isImage($type)) {
            return $type;
        }

        $image = @getimagesize($path);
        if ($image === false || $image[0] <= 0 || $image[1] <= 0) {
            return null;
        }

        return image_type_to_mime_type($image[2]) === $type ? $type : null;
    }
}
