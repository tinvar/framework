<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/** An append that named another length than the one at its key: nothing was written. */
final class OffsetMismatch extends \RuntimeException
{
    public function __construct(public readonly int $actual)
    {
        parent::__construct("What is at the key is {$actual} bytes long, not the length the append named.");
    }
}
