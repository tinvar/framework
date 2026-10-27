<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

/**
 * What was wrong with an import's rows: every reason counted, the first
 * TransferRecord::MAX_ERRORS kept to show the user. A message is the form
 * request's or the runner's own, naming a field -- never a cell's value,
 * which the user has and the record need not keep.
 *
 * @internal
 */
final class RowFailures
{
    public int $count = 0;

    /** @var list<array{row: int, field: string, message: string}> */
    public array $kept = [];

    public function add(int $row, string $field, string $message): void
    {
        $this->count++;
        if (\count($this->kept) < TransferRecord::MAX_ERRORS) {
            $this->kept[] = ['row' => $row, 'field' => $field, 'message' => mb_substr($message, 0, 500)];
        }
    }
}
