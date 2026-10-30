<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Http\HttpException;
use Tinvar\Storage\FileTypes;

/**
 * Every uploaded image drawn again from its pixels, and nothing else of the
 * file kept (ADR 0103).
 *
 * A photo carries what its camera wrote beside it -- where it was taken, to
 * the metre, in its EXIF GPS block; the device and its serial number; a
 * thumbnail that may show what was cropped out -- and a PNG or a WebP
 * carries text chunks of anything. Kept as sent, all of it was served back
 * to whoever the file is shown to. And a file that sniffs as an image may
 * still carry bytes after its image ends, a polyglot another reader takes
 * for something else. So the image is decoded and encoded afresh in its
 * own format, which keeps the pixels and drops the rest. A JPEG is first
 * turned upright as its EXIF orientation says, since the orientation goes
 * with the rest.
 *
 * Decoded only within limits, read from the header before any pixel is:
 * MAX_DIMENSION a side and MAX_PIXELS in all, and only where the decoded
 * image fits in what is left of PHP's memory limit. A small file can
 * declare an enormous image -- a decompression bomb -- and decoding it
 * would take the worker down. Past any of them the upload is refused.
 *
 * An animated GIF keeps its first frame; an animated WebP, which GD does
 * not decode, is refused. With no GD there is nothing to draw images with,
 * and every image upload is refused rather than kept as sent.
 */
final readonly class ImageSanitizer
{
    /** The longest side an image may have. */
    public const int MAX_DIMENSION = 8192;

    /** The most pixels an image may have: 16.7 megapixels, 64 MB decoded. */
    public const int MAX_PIXELS = 16_777_216;

    /**
     * Bytes asked of the memory limit per pixel: four for the decoded
     * image, four more for a JPEG's copy turned upright, and some to spare.
     */
    private const int BYTES_PER_PIXEL = 10;

    public function __construct(
        private int $maxDimension = self::MAX_DIMENSION,
        private int $maxPixels = self::MAX_PIXELS,
        private int $jpegQuality = 90,
        private int $webpQuality = 90,
    ) {
        if ($maxDimension < 1 || $maxPixels < 1 || $jpegQuality < 1 || $jpegQuality > 100 || $webpQuality < 1 || $webpQuality > 100) {
            throw new \InvalidArgumentException('Image limits are positive, and qualities between 1 and 100.');
        }
    }

    /**
     * The image at $path drawn again into a new file, whose path is
     * returned: the caller stores it, and removes it after.
     *
     * @param string $type the type FileTypes sniffed it as, an image
     * @throws HttpException 422 for an image that is not drawn: too large, or one GD cannot read
     * @throws \RuntimeException where there is no GD to draw with
     */
    public function sanitize(string $path, string $type): string
    {
        if (!\extension_loaded('gd')) {
            throw new \RuntimeException('Uploaded images are drawn again before they are kept, and PHP has no GD to draw them with: every image upload is refused.');
        }

        // The header alone: nothing is decoded until the size is known.
        $size = @getimagesize($path);
        if ($size === false || image_type_to_mime_type($size[2]) !== $type) {
            throw self::refused('The image cannot be read.');
        }
        [$width, $height] = $size;
        if ($width < 1 || $height < 1 || $width > $this->maxDimension || $height > $this->maxDimension || $width * $height > $this->maxPixels) {
            throw self::refused('The image is larger than images may be: ' . $this->maxDimension . ' pixels a side and ' . $this->maxPixels . ' in all.');
        }
        if (!$this->fits($width * $height)) {
            throw self::refused('The image is larger than this server can draw now.');
        }

        // GD's readers report a damaged file as a warning, some of them
        // past `@`: each is the image refused, below, and no more.
        set_error_handler(static fn (): bool => true);
        try {
            $image = match ($type) {
                'image/png' => imagecreatefrompng($path),
                'image/jpeg' => imagecreatefromjpeg($path),
                'image/gif' => imagecreatefromgif($path),
                'image/webp' => imagecreatefromwebp($path),
                default => throw new \InvalidArgumentException("'{$type}' is not an image type uploads draw."),
            };
        } finally {
            restore_error_handler();
        }
        if ($image === false) {
            throw self::refused('The image cannot be read.');
        }

        try {
            if (imagesx($image) !== $width || imagesy($image) !== $height) {
                throw self::refused('The image is not the size its header says.');
            }
            if ($type === 'image/jpeg') {
                $image = self::upright($image, $path);
            }

            return $this->encode($image, $type, $type !== 'image/png' || self::pngHasAlpha($path));
        } finally {
            unset($image);
        }
    }

    private function encode(\GdImage $image, string $type, bool $alpha): string
    {
        $out = tempnam(sys_get_temp_dir(), 'tinvar-image-');
        if ($out === false) {
            throw new \RuntimeException('No temporary file to draw an image into.');
        }

        // Its alpha channel kept where it has one. Asked of a PNG without
        // one, GD would write every pixel with an alpha byte it never
        // needed, a third larger again.
        if ($alpha && ($type === 'image/png' || $type === 'image/webp')) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        $written = match ($type) {
            'image/png' => imagepng($image, $out, 6),
            'image/jpeg' => imagejpeg($image, $out, $this->jpegQuality),
            'image/gif' => imagegif($image, $out),
            default => imagewebp($image, $out, $this->webpQuality),
        };
        if (!$written || FileTypes::sniffFile($out) !== $type) {
            @unlink($out);
            throw new \RuntimeException('An image drawn again did not come out as its own type.');
        }

        return $out;
    }

    /** Turned as the JPEG's EXIF orientation says (1 to 8), before the EXIF goes. */
    private static function upright(\GdImage $image, string $path): \GdImage
    {
        $exif = \function_exists('exif_read_data') ? @exif_read_data($path, 'IFD0') : false;
        $orientation = \is_array($exif) && \is_int($exif['Orientation'] ?? null) ? $exif['Orientation'] : 1;

        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        // Counter-clockwise, as GD turns: 6 is a quarter turn clockwise
        // to stand up, 8 one back; 5 and 7 are the mirrored pair of those.
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $turned = imagerotate($image, $angle, 0);
        if ($turned === false) {
            throw new \RuntimeException('An image could not be turned upright.');
        }
        return $turned;
    }

    /** Whether a PNG's header says its pixels carry alpha: grey or colour with an alpha sample (types 4 and 6). */
    private static function pngHasAlpha(string $path): bool
    {
        $head = (string) @file_get_contents($path, length: 26);

        return \strlen($head) === 26 && \in_array(\ord($head[25]), [4, 6], true);
    }

    /** Whether $pixels decoded fit in what is left of PHP's memory limit. */
    private function fits(int $pixels): bool
    {
        $limit = self::bytes((string) \ini_get('memory_limit'));

        return $limit < 0 || memory_get_usage(true) + $pixels * self::BYTES_PER_PIXEL < $limit;
    }

    private static function bytes(string $setting): int
    {
        $setting = trim($setting);
        if ($setting === '' || $setting === '-1') {
            return -1;
        }
        $number = (int) $setting;

        return match (strtolower(substr($setting, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function refused(string $message): HttpException
    {
        return new HttpException(422, $message, 'upload_image');
    }
}
