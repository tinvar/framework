<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Data\Data;

/**
 * One export or import as its user is shown it (TransferStatus): where it
 * is, how many rows it wrote or read, an import's failing rows, and an
 * export's download link while its file is kept.
 */
final readonly class TransferData extends Data
{
    /**
     * @param string $kind 'export' or 'import'
     * @param string $status 'queued', 'running', 'done' or 'failed'
     * @param list<TransferErrorData> $errors the first of an import's failing rows
     * @param int $createdAt seconds since the epoch
     * @param int|null $finishedAt seconds since the epoch; null until it has
     * @param string|null $download a `/_download/` path, for this viewer, while the file is kept
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public string $format,
        public string $status,
        public int $rows,
        public int $errorCount,
        public array $errors,
        public string $failure,
        public int $createdAt,
        public ?int $finishedAt,
        public ?string $download,
    ) {}
}
