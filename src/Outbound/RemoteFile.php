<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/** A file RemoteFiles fetched, checked against its digest, and stored. */
final readonly class RemoteFile
{
    public function __construct(
        /** Where it is stored: the prefix it was asked for, a random name, its extension. */
        public string $key,
        public int $bytes,
        /** Its SHA-256, in lowercase hex: the one it was fetched against. */
        public string $sha256,
        public int $milliseconds,
    ) {}
}
