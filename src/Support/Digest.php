<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * A stable, non-reversible handle on one failure.
 *
 * Enough to find the log line, not enough to learn anything: the class and
 * location go through a hash, and the message never does, since it may
 * quote the client's own input back.
 *
 * Here rather than on ExceptionHandler, which still answers with it, because
 * the encoder reports a rescued field under the same digest and has no
 * business depending on the HTTP layer to compute one.
 */
final class Digest
{
    public static function of(\Throwable $e): string
    {
        return substr(hash('sha256', $e::class . '|' . $e->getFile() . '|' . $e->getLine()), 0, 16);
    }
}
