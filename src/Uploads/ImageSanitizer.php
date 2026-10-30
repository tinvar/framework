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
 *
 * An AVIF is drawn by GD where PHP's GD was built with libavif, and
 * otherwise by Imagick, where its ImageMagick reads and writes AVIF: its
 * pixels are taken out as raw RGBA, and a new image made from those bytes
 * alone and encoded -- the same redraw, nothing of the file kept but its
 * pixels. Where neither can, an AVIF is refused as a type this server does
 * not take (415). An animated AVIF never sniffs as one (FileTypes). HEIC is
 * not taken: no build at hand encodes HEVC, and an image is redrawn in its
 * own format (ADR 0126).
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
        if ($type === 'image/avif' && !self::gdAvif()) {
            return $this->sanitizeWithImagick($path, $type);
        }
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
                'image/avif' => imagecreatefromavif($path),
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
        if ($alpha && \in_array($type, ['image/png', 'image/webp', 'image/avif'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        $written = match ($type) {
            'image/png' => imagepng($image, $out, 6),
            'image/jpeg' => imagejpeg($image, $out, $this->jpegQuality),
            'image/gif' => imagegif($image, $out),
            'image/avif' => imageavif($image, $out, $this->webpQuality),
            default => imagewebp($image, $out, $this->webpQuality),
        };
        if (!$written || FileTypes::sniffFile($out) !== $type) {
            @unlink($out);
            throw new \RuntimeException('An image drawn again did not come out as its own type.');
        }

        return $out;
    }

    /** Whether this PHP's GD reads and writes AVIF. */
    public static function gdAvif(): bool
    {
        return \function_exists('imagecreatefromavif') && \function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false) === true;
    }

    /** Whether this PHP's Imagick reads and writes $format ('AVIF'). */
    public static function imagickCan(string $format): bool
    {
        return class_exists(\Imagick::class) && \in_array($format, \Imagick::queryFormats($format), true);
    }

    /** Whether an uploaded image of $type can be drawn again here at all. */
    public static function draws(string $type): bool
    {
        return match ($type) {
            'image/avif' => self::gdAvif() || self::imagickCan('AVIF'),
            'image/png', 'image/jpeg', 'image/gif', 'image/webp' => \extension_loaded('gd'),
            default => false,
        };
    }

    /**
     * An AVIF drawn again by Imagick, for a GD built without libavif: its
     * pixels read out as raw 8-bit RGBA, and a new image built from those
     * bytes and its size alone -- no profile, no metadata, nothing after the
     * image -- then encoded afresh. Within the same limits as GD's, checked
     * on the header first and held as ImageMagick's own resource limits
     * while it decodes.
     */
    private function sanitizeWithImagick(string $path, string $type): string
    {
        if (!self::imagickCan('AVIF')) {
            throw new HttpException(415, 'The file is not of a type this upload takes.', 'upload_type');
        }

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

        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_WIDTH, $this->maxDimension);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_HEIGHT, $this->maxDimension);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_AREA, $this->maxPixels);

        try {
            $read = new \Imagick();
            $read->readImage('avif:' . $path);
            if ($read->getNumberImages() !== 1) {
                throw self::refused('An animated image cannot be drawn again; send a still one.');
            }
            if ($read->getImageWidth() !== $width || $read->getImageHeight() !== $height) {
                throw self::refused('The image is not the size its header says.');
            }
            $read->setImageFormat('rgba');
            $read->setImageDepth(8);
            $pixels = $read->getImageBlob();
            $read->clear();
        } catch (\ImagickException) {
            throw self::refused('The image cannot be read.');
        }
        if (\strlen($pixels) !== $width * $height * 4) {
            throw self::refused('The image cannot be read.');
        }

        $out = tempnam(sys_get_temp_dir(), 'tinvar-image-');
        if ($out === false) {
            throw new \RuntimeException('No temporary file to draw an image into.');
        }
        try {
            $drawn = new \Imagick();
            $drawn->setSize($width, $height);
            $drawn->setOption('depth', '8');
            $drawn->setFormat('rgba');
            $drawn->readImageBlob($pixels);
            $drawn->setImageFormat('avif');
            $drawn->setImageCompressionQuality($this->webpQuality);
            $drawn->stripImage();
            $drawn->writeImage('avif:' . $out);
            $drawn->clear();
        } catch (\ImagickException) {
            @unlink($out);
            throw new \RuntimeException('An image drawn again could not be encoded.');
        }
        if (FileTypes::sniffFile($out) !== $type) {
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
