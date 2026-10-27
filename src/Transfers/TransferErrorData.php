<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Data\Data;

/** One reason one row of an import was refused: its row as a spreadsheet numbers it, the column, and why. */
final readonly class TransferErrorData extends Data
{
    public function __construct(
        public int $row,
        public string $field,
        public string $message,
    ) {}
}
