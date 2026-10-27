<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

/**
 * A file an import will not read: not UTF-8 text, a cell too long, a header
 * that names other columns, too many rows. Its message is the user's to
 * read -- it names a row and a reason, never a value from the file.
 */
final class CsvRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
