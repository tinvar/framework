<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

/**
 * One export or import a user started: where it is, and what came of it.
 *
 * Bound as a ticket is to where it was started -- the tenant claim, the
 * panel, the user -- and read back only there (TransferStore::recent()),
 * so the list a user is shown is theirs, in this tenant and this panel.
 * The user is kept by id, not by their auth epoch: a record outlives a
 * sign-in, and the download link minted from it carries the epoch instead.
 */
final readonly class TransferRecord
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';

    /** How many failing rows an import keeps the errors of; the rest are counted. */
    public const int MAX_ERRORS = 100;

    /**
     * @param CompiledTransfer::EXPORT|CompiledTransfer::IMPORT $kind
     * @param self::QUEUED|self::RUNNING|self::DONE|self::FAILED $status
     * @param list<array{row: int, field: string, message: string}> $errors an import's failing
     *        rows, by line, up to MAX_ERRORS of them
     * @param string $key where its file is: an export's once written, an import's once uploaded;
     *        '' for none
     * @param string $failure what the user is told when it failed, never an exception's message
     * @param int $checked an import's rows checked so far, before any is written; for an import
     *        `rows` is those written, each in the transaction that wrote it (TransferRunner::import())
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public string $format,
        public string $tenant,
        public string $panel,
        public string $user,
        public string $status,
        public int $createdAt,
        public int $rows = 0,
        public int $errorCount = 0,
        public array $errors = [],
        public string $key = '',
        public int $size = 0,
        public ?int $finishedAt = null,
        public int $expiresAt = 0,
        public string $fileName = '',
        public string $failure = '',
        public int $checked = 0,
    ) {}

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    public function isDownloadable(int $now): bool
    {
        return $this->kind === CompiledTransfer::EXPORT
            && $this->status === self::DONE
            && $this->key !== ''
            && $this->expiresAt > $now;
    }
}
