<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * An image RemoteImages fetched and read: its type from its bytes, its size
 * from its header, and -- once stored -- where.
 *
 * The bytes are kept out of a dump, which would otherwise print a megabyte.
 */
final readonly class RemoteImage
{
    public function __construct(
        /** image/png, image/jpeg, image/gif or image/webp: what the bytes are, never what the server said. */
        public string $type,
        public int $width,
        public int $height,
        public string $bytes,
        public ?string $key = null,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['type' => $this->type, 'width' => $this->width, 'height' => $this->height, 'bytes' => \strlen($this->bytes) . ' bytes', 'key' => $this->key];
    }
}
