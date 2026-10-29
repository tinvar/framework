<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;

/**
 * A user's own exports and imports, as a page shows them: for a loader to
 * take by type and return inside its Data.
 *
 * Read in the context the loader runs in -- the tenant claim, the panel,
 * the signed-in user -- and never another: records started anywhere else
 * are not found. Each finished export carries a download path minted for
 * this viewer on their current auth epoch. Minting writes nothing, so this
 * is safe in the read-only transaction loaders run in.
 */
final readonly class TransferStatus
{
    public function __construct(
        private Transfers $transfers,
        private AuthContext $auth,
        private Orm $orm,
        private ?PanelContext $panels = null,
    ) {}

    /** @return list<TransferData> the newest $limit, newest first; none for nobody signed in */
    public function recent(int $limit = 10): array
    {
        $user = $this->auth->id();
        if ($user === null) {
            return [];
        }

        return array_map(
            fn (TransferRecord $record): TransferData => $this->data($record, $user),
            $this->transfers->store->recent(TenantClaim::current($this->orm), $this->panels?->currentOrNull() ?? '', $user, max(1, min($limit, 100))),
        );
    }

    /** One of the viewer's own transfers, or null for any other. */
    public function find(string $id): ?TransferData
    {
        $user = $this->auth->id();
        if ($user === null) {
            return null;
        }
        $record = $this->transfers->store->find($id, TenantClaim::current($this->orm), $this->panels?->currentOrNull() ?? '', $user);

        return $record === null ? null : $this->data($record, $user);
    }

    private function data(TransferRecord $record, string $user): TransferData
    {
        $now = $this->transfers->now();
        $stale = ($record->status === TransferRecord::QUEUED || $record->status === TransferRecord::RUNNING)
            && $record->createdAt < $now - Transfers::STALE_AFTER;

        return new TransferData(
            id: $record->id,
            kind: $record->kind,
            name: $record->name,
            format: $record->format,
            status: $stale ? TransferRecord::FAILED : $record->status,
            rows: $record->rows,
            errorCount: $record->errorCount,
            errors: array_map(
                static fn (array $error): TransferErrorData => new TransferErrorData($error['row'], $error['field'], $error['message']),
                $record->errors,
            ),
            failure: $stale ? self::unfinished($record) : $record->failure,
            createdAt: $record->createdAt,
            finishedAt: $record->finishedAt,
            download: $record->isDownloadable($now) ? $this->transfers->downloadPath($record, $user, $this->auth->epoch()) : null,
        );
    }

    /**
     * What a user is told of one that never finished. An import that had
     * written rows says how many, and that the rest were not: started again
     * whole, it would write those rows a second time.
     */
    private static function unfinished(TransferRecord $record): string
    {
        if ($record->kind === CompiledTransfer::IMPORT && $record->rows > 0) {
            return "It did not finish. The first {$record->rows} rows were imported and the rest were not; import only the rest.";
        }

        return 'It did not finish. Start it again.';
    }
}
