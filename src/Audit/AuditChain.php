<?php

declare(strict_types=1);

namespace Tinvar\Audit;

use Tinvar\Support\Signer;

/**
 * The hashes that chain a tenant's audit log together (ADR 0150), so an
 * entry edited, deleted or put in out of order is found by verify().
 *
 * Each entry's hash is a MAC, under a key derived from the application key
 * for this alone, over the hash of the entry before it and the entry's own
 * fields. Someone who can write the database and not read the key can
 * change an entry, but not make a hash the next entry agrees with: the
 * chain breaks where they began. The first entry of a tenant's log is
 * chained to a genesis value the tenant's name alone makes; once pruning
 * has taken the oldest away, to the last one pruned, held in the tenant's
 * head with a MAC of its own.
 *
 * What a chain cannot show by itself is its newest entries taken away
 * with the head put back to match: an operator who needs that proved keeps
 * the head somewhere the database's writers cannot reach (AuditLog::head()).
 */
final readonly class AuditChain
{
    /** The purpose the key is derived for. */
    public const string PURPOSE = 'audit-chain';

    private Signer $signer;

    public function __construct(#[\SensitiveParameter] string $key)
    {
        $this->signer = new Signer($key, self::PURPOSE);
    }

    /** What a tenant's first entry is chained to. */
    public function genesis(string $tenant): string
    {
        return bin2hex($this->signer->sign("genesis\n{$tenant}"));
    }

    /**
     * An entry's hash: over the one before it and its own fields, in the
     * order fields() puts them.
     *
     * @param list<string|int|null> $fields
     */
    public function link(string $previous, array $fields): string
    {
        return bin2hex($this->signer->sign($previous . "\n" . json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }

    /** The MAC over where pruning left a tenant's log: the last entry taken away, and its hash. */
    public function anchor(string $tenant, int $prunedThrough, string $anchor): string
    {
        return bin2hex($this->signer->sign("anchor\n{$tenant}\n{$prunedThrough}\n{$anchor}"));
    }

    public function anchorHolds(string $tenant, int $prunedThrough, string $anchor, string $mac): bool
    {
        return hash_equals($this->anchor($tenant, $prunedThrough, $anchor), $mac);
    }

    /**
     * An entry's fields as its hash covers them, from its row as written
     * or as read back: every column but the two hashes, the moment in one
     * form whatever form the database keeps it in.
     *
     * @param array<string, mixed> $row
     * @return list<string|int|null>
     */
    public static function fields(array $row, string $occurredAt): array
    {
        $text = static fn (string $column): ?string => isset($row[$column]) ? (string) $row[$column] : null;

        return [
            (string) $row['id'],
            (string) $row['tenant'],
            (int) $row['sequence'],
            $occurredAt,
            (string) $row['event'],
            $text('subject_type'),
            $text('subject_id'),
            (string) $row['actor_type'],
            $text('actor_id'),
            $text('impersonator_id'),
            $text('job'),
            $text('job_id'),
            $text('started_by'),
            $text('action'),
            $text('panel'),
            $text('host'),
            $text('request_id'),
            $text('ip'),
            $text('changes'),
            $text('data'),
        ];
    }
}
