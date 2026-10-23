<?php

declare(strict_types=1);

namespace Tinvar\Codec;

/**
 * Marks a byte string that must travel as a MessagePack `bin`, not a `str`.
 *
 * RSC output chunks are raw bytes and are not necessarily valid UTF-8. Wrapping
 * them keeps them out of the string path, so they cross the socket untouched.
 */
final readonly class Bin
{
    public function __construct(public string $bytes) {}
}
