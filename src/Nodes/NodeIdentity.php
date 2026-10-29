<?php

declare(strict_types=1);

namespace Tinvar\Nodes;

/** A node a NodeAuthority believes: its id, the environment it is in, and its certificate's SHA-256 fingerprint. */
final readonly class NodeIdentity
{
    public function __construct(
        public string $id,
        public string $environment,
        public string $fingerprint,
    ) {}
}
