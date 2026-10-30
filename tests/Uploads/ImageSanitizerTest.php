<?php

declare(strict_types=1);

/**
 * Uploaded images drawn again from their pixels (ADR 0103): metadata and
 * anything after the pixels gone, a JPEG turned upright first, and an image
 * whose header declares too many pixels refused before one is decoded.
 */

namespace Tinvar\Tests\Uploads\ImageSanitizer;

use Tinvar\Http\HttpException;
use Tinvar\Storage\FileTypes;
use Tinvar\Uploads\ImageSanitizer;

function file(string $bytes): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'tinvar-image-test-');
    file_put_contents($path, $bytes);
    $owner = getmypid();
    register_shutdown_function(static function () use ($path, $owner): void {
        if (getmypid() === $owner) {
            @unlink($path);
        }
    });

    return $path;
}

/** An image of $width by $height in $type, drawn by GD. */
function drawn(string $type, int $width = 4, int $height = 2): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 30, 30));
    ob_start();
    match ($type) {
        'image/png' => imagepng($image),
        'image/jpeg' => imagejpeg($image),
        'image/gif' => imagegif($image),
        default => imagewebp($image),
    };

    return (string) ob_get_clean();
}

/** A JPEG with an EXIF block -- orientation 6 and a GPS position -- and a comment. */
function photo(): string
{
    $tiff = 'II*' . "\0" . pack('V', 8)
        // IFD0: Orientation 6, and where the GPS IFD is.
        . pack('v', 2)
        . pack('vvVvv', 0x0112, 3, 1, 6, 0)
        . pack('vvVV', 0x8825, 4, 1, 38)
        . pack('V', 0)
        // GPS IFD: latitude reference, and a latitude.
        . pack('v', 2)
        . pack('vvVa4', 0x0001, 2, 2, "N\0\0\0")
        . pack('vvVV', 0x0002, 5, 3, 68)
        . pack('V', 0)
        . pack('VVVVVV', 51, 1, 30, 1, 2667, 100);
    $exif = "Exif\0\0" . $tiff;
    $comment = 'taken at 51 30 26.67 N, the back garden';
    $jpeg = drawn('image/jpeg', 4, 2);

    return "\xFF\xD8"
        . "\xFF\xE1" . pack('n', \strlen($exif) + 2) . $exif
        . "\xFF\xFE" . pack('n', \strlen($comment) + 2) . $comment
        . substr($jpeg, 2);
}

/** A PNG's IHDR saying $width by $height, its CRC made good, over a 1x1 image. */
function claiming(int $width, int $height): string
{
    $png = drawn('image/png', 1, 1);
    $ihdr = 'IHDR' . pack('NN', $width, $height) . substr($png, 24, 5);

    return substr($png, 0, 12) . $ihdr . pack('N', crc32($ihdr)) . substr($png, 33);
}

beforeEach(function () {
    if (!\extension_loaded('gd')) {
        $this->markTestSkipped('GD is not available.');
    }
});

it('drops a photo\'s EXIF, its GPS position and its comments, and turns it upright first', function () {
    $original = file(photo());
    expect(FileTypes::sniffFile($original))->toBe('image/jpeg')
        ->and(\function_exists('exif_read_data') ? (@exif_read_data($original)['Orientation'] ?? null) : 6)->toBe(6);

    $kept = (string) file_get_contents(new ImageSanitizer()->sanitize($original, 'image/jpeg'));

    expect($kept)->not->toContain('Exif')
        ->and($kept)->not->toContain('back garden')
        ->and($kept)->not->toContain(pack('VV', 51, 1))
        ->and(FileTypes::sniff($kept))->toBe('image/jpeg')
        // A quarter turn: 4 by 2 stands up as 2 by 4, where EXIF is read.
        ->and(array_slice((array) getimagesizefromstring($kept), 0, 2))->toBe(\function_exists('exif_read_data') ? [2, 4] : [4, 2]);
})->skip(!\function_exists('imagecreatefromjpeg'), 'GD has no JPEG.');

it('drops a PNG\'s text chunks and whatever follows its image', function () {
    $png = drawn('image/png');
    $text = 'tEXt' . "Comment\0" . 'my home address';
    $marked = substr($png, 0, 33) . pack('N', \strlen($text) - 4) . $text . pack('N', crc32($text)) . substr($png, 33) . '<script>alert(1)</script>';
    $original = file($marked);

    $kept = (string) file_get_contents(new ImageSanitizer()->sanitize($original, 'image/png'));

    expect(FileTypes::sniffFile($original))->toBe('image/png')
        ->and($kept)->not->toContain('home address')
        ->and($kept)->not->toContain('<script>')
        ->and(array_slice((array) getimagesizefromstring($kept), 0, 2))->toBe([4, 2]);
});

it('keeps a GIF and a WebP as what they are', function (string $type) {
    $kept = new ImageSanitizer()->sanitize(file(drawn($type)), $type);

    expect(FileTypes::sniffFile($kept))->toBe($type);
})->with(['image/gif', 'image/webp']);

// A few bytes declaring an image of ten billion pixels: refused from its
// header, before GD is asked to make room for one.
it('refuses an image whose header declares more than images may have, before decoding it', function (int $width, int $height) {
    $before = memory_get_peak_usage();

    expect(fn () => new ImageSanitizer()->sanitize(file(claiming($width, $height)), 'image/png'))
        ->toThrow(fn (HttpException $e) => expect([$e->status, $e->code_])->toBe([422, 'upload_image']))
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(8 * 1024 * 1024);
})->with([
    'too wide' => [100_000, 1],
    'too tall' => [1, ImageSanitizer::MAX_DIMENSION + 1],
    'too many in all' => [8000, 8000],
]);

it('takes limits of the application\'s own', function () {
    expect(fn () => new ImageSanitizer(maxDimension: 3)->sanitize(file(drawn('image/png')), 'image/png'))
        ->toThrow(HttpException::class, 'larger than images may be')
        ->and(fn () => new ImageSanitizer(maxPixels: 7)->sanitize(file(drawn('image/png')), 'image/png'))
        ->toThrow(HttpException::class, 'larger than images may be')
        ->and(fn () => new ImageSanitizer(maxPixels: 0))->toThrow(\InvalidArgumentException::class);
});

it('refuses an image GD cannot read, and one whose type is not what it sniffed as', function () {
    $truncated = substr(drawn('image/png', 64, 64), 0, 60);

    expect(fn () => new ImageSanitizer()->sanitize(file($truncated), 'image/png'))->toThrow(HttpException::class)
        ->and(fn () => new ImageSanitizer()->sanitize(file(drawn('image/gif')), 'image/png'))->toThrow(HttpException::class, 'cannot be read');
});

// Asked to save alpha it never had, GD wrote every pixel of an RGB PNG with
// an alpha byte, a third larger, and a receipt near its limit went over.
it('keeps a PNG\'s alpha where it has one, and adds none where it has not', function () {
    $rgb = (string) file_get_contents(new ImageSanitizer()->sanitize(file(drawn('image/png')), 'image/png'));

    $translucent = imagecreatetruecolor(4, 2);
    imagealphablending($translucent, false);
    imagesavealpha($translucent, true);
    imagefilledrectangle($translucent, 0, 0, 3, 1, (int) imagecolorallocatealpha($translucent, 10, 20, 30, 64));
    ob_start();
    imagepng($translucent);
    $rgba = (string) file_get_contents(new ImageSanitizer()->sanitize(file((string) ob_get_clean()), 'image/png'));

    // The colour type, in the IHDR: 2 is RGB, 6 RGB with alpha.
    expect(\ord($rgb[25]))->toBe(2)
        ->and(\ord($rgba[25]))->toBe(6);
});
