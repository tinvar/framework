<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

use Tinvar\Storage\Storage;
use Tinvar\Storage\StorageKey;
use Tinvar\Storage\FileTypes;

/**
 * Images fetched from a URL someone gave the application -- an avatar, a
 * logo, a product photo by link: tinvar-spec.md's "image sources".
 *
 *     $image = $images->store($url, prefix: 'logos');
 *
 * Fetched once, by the server, through OutboundHttp -- resolved, vetted and
 * pinned, up to three redirects each vetted again, https only -- and never
 * hot-linked: a page that showed the tenant's URL would hand every viewer's
 * address to whoever runs it, and show whatever it served next.
 *
 * What comes back is held to what an upload is (FileTypes): PNG, JPEG, GIF
 * or WebP by its bytes, whatever the Content-Type said -- no SVG, which is
 * a document that can carry script -- with a header PHP's image reader
 * agrees is that format, and a size from it. The size is capped as well as
 * the bytes: a few kilobytes can claim a hundred thousand pixels square,
 * which whoever decodes it to make a thumbnail pays for in memory.
 *
 * A stored image's key is the prefix asked for, a random name and the
 * extension of the type it was sniffed as.
 */
final readonly class RemoteImages
{
    /** The default cap on an image's bytes: 10 MiB. */
    public const int MAX_BYTES = 10_485_760;

    /** The most pixels an image may claim: 50 million, a large camera's. */
    public const int MAX_PIXELS = 50_000_000;

    /** The longest side an image may claim. */
    public const int MAX_SIDE = 20_000;

    /** Seconds a fetch may take, redirects included. */
    public const float TIMEOUT = 15.0;

    /** Redirects followed, each vetted again. */
    public const int MAX_REDIRECTS = 3;

    public function __construct(private OutboundHttp $http, private ?Storage $storage = null) {}

    /**
     * Fetches the image at $url and reads it.
     *
     * @throws OutboundRefused for a URL or destination that is refused
     * @throws OutboundFailed when the transfer did not complete, or was over the cap
     * @throws RemoteContentRefused for a status other than a 2xx, or bytes that are not an image taken
     */
    public function fetch(string $url, int $maxBytes = self::MAX_BYTES): RemoteImage
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('An image\'s cap is at least a byte.');
        }
        $target = Target::parse($url);
        if ($target->scheme !== 'https') {
            throw new OutboundRefused('scheme', "{$target->origin()} is not https; an image source is fetched over a connection whose certificate was checked.");
        }

        $response = $this->http->send(new OutboundRequest(
            'GET',
            $url,
            ['accept' => 'image/png, image/jpeg, image/gif, image/webp'],
            timeout: self::TIMEOUT,
            maxResponseBytes: $maxBytes,
            maxRedirects: self::MAX_REDIRECTS,
        ));
        if (!$response->successful()) {
            throw new RemoteContentRefused(RemoteContentRefused::STATUS, "GET {$target->origin()} answered {$response->status}.", $response->status);
        }

        return self::read($response->body, $target->origin());
    }

    /**
     * Fetches the image at $url, reads it, and stores it under $prefix.
     *
     * @throws OutboundRefused|OutboundFailed|RemoteContentRefused as fetch() does
     */
    public function store(string $url, string $prefix, int $maxBytes = self::MAX_BYTES): RemoteImage
    {
        if ($this->storage === null) {
            throw new \LogicException('RemoteImages stores into the Storage it was given, and was given none.');
        }
        if (!StorageKey::isValidPrefix($prefix)) {
            throw new \InvalidArgumentException("'{$prefix}' is not a storage prefix.");
        }

        $image = $this->fetch($url, $maxBytes);
        $key = "{$prefix}/" . bin2hex(random_bytes(16)) . '.' . FileTypes::extension($image->type);
        $stream = fopen('php://memory', 'w+b');
        \assert($stream !== false);
        fwrite($stream, $image->bytes);
        rewind($stream);
        try {
            $this->storage->put($key, $stream);
        } finally {
            fclose($stream);
        }

        return new RemoteImage($image->type, $image->width, $image->height, $image->bytes, $key);
    }

    /** $bytes as an image taken, or RemoteContentRefused. */
    private static function read(string $bytes, string $origin): RemoteImage
    {
        $type = FileTypes::sniff(substr($bytes, 0, FileTypes::HEAD_BYTES));
        if ($type === null || !FileTypes::isImage($type)) {
            throw new RemoteContentRefused(RemoteContentRefused::TYPE, "What {$origin} sent is not a PNG, JPEG, GIF or WebP image.");
        }

        // The header, never the pixels: it says what format it is and how
        // large it claims to be, and must agree with the sniffed type.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0 || image_type_to_mime_type($info[2]) !== $type) {
            throw new RemoteContentRefused(RemoteContentRefused::TYPE, "What {$origin} sent begins like an image and does not read as one.");
        }
        [$width, $height] = [$info[0], $info[1]];
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new RemoteContentRefused(RemoteContentRefused::DIMENSIONS, "The image from {$origin} claims {$width}x{$height} pixels, more than is decoded.");
        }

        return new RemoteImage($type, $width, $height, $bytes);
    }
}
