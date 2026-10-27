<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Orm\Cursors;
use Tinvar\Support\Uuid;

/**
 * The cursors an export pages its rows by, which no reader ever holds: made
 * and opened inside the job, and kept between its runs only in its
 * checkpoint -- sealed in the job's payload under the payload's MAC, beside
 * the tenant, panel, user and epoch it runs as (Jobs\Envelope).
 *
 * So the MAC a reader's cursor needs is the envelope's here, and a cursor
 * carries no key of its own: a run that paused hands the next one the
 * anchor's key, which way it reads, and a hash of the query it was made
 * for. It opens for that query alone -- the same export, its arguments read
 * again in the same tenant -- and the anchor is read again under the
 * export's own scope when it does (ADR 0057).
 */
final readonly class ExportCursors implements Cursors
{
    public function seal(string $query, string $anchor, bool $after): string
    {
        return ($after ? 'a' : 'b') . '.' . $anchor . '.' . hash('sha256', $query);
    }

    public function open(string $query, string $cursor): ?array
    {
        $parts = explode('.', $cursor);
        if (\count($parts) !== 3 || !\in_array($parts[0], ['a', 'b'], true) || !Uuid::isValid($parts[1])
            || !hash_equals(hash('sha256', $query), $parts[2])) {
            return null;
        }

        return [$parts[1], $parts[0] === 'a'];
    }
}
