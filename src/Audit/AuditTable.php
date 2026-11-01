<?php

declare(strict_types=1);

namespace Tinvar\Audit;

use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * The audit log's two tables, for the application's migration to make
 * (ADR 0150):
 *
 *   - `audit_entries`, the log itself: append-only, one row an entry, each
 *     carrying its tenant, its place in that tenant's log and the hash
 *     that chains it to the one before;
 *   - `audit_heads`, one row a tenant: the last entry's place and hash,
 *     which each new entry is chained to under the row's lock, and where
 *     pruning left the log's start.
 */
final class AuditTable
{
    public const string ENTRIES = 'audit_entries';

    public const string HEADS = 'audit_heads';

    /** The longest a tenant's name, an actor's id or a class name may be here. */
    public const int NAME = 191;

    public static function define(Schema $schema, bool $ifNotExists = false): void
    {
        $schema->create(self::ENTRIES, static function (Table $rows): void {
            $rows->string('id', 36)->primary();
            $rows->string('tenant', self::NAME);
            $rows->bigInteger('sequence');
            $rows->timestamp('occurred_at');
            $rows->string('event', 64);
            $rows->string('subject_type', self::NAME)->nullable();
            $rows->string('subject_id', 64)->nullable();
            $rows->enum('actor_type', ActorKind::class);
            $rows->string('actor_id', self::NAME)->nullable();
            $rows->string('impersonator_id', self::NAME)->nullable();
            $rows->string('job', self::NAME)->nullable();
            $rows->string('job_id', 64)->nullable();
            $rows->string('started_by', self::NAME)->nullable();
            $rows->string('action', self::NAME)->nullable();
            $rows->string('panel', 64)->nullable();
            $rows->string('host', 255)->nullable();
            $rows->string('request_id', 64)->nullable();
            $rows->string('ip', 45)->nullable();
            // Text, never JSON: a JSON column may store what it was given
            // in a form of its own, and the hash is over what was written.
            $rows->text('changes')->nullable();
            $rows->text('data')->nullable();
            $rows->string('previous_hash', 64);
            $rows->string('hash', 64);
            $rows->unique(['tenant', 'sequence'], 'audit_entries_tenant_sequence');
            $rows->index(['tenant', 'subject_type', 'subject_id', 'sequence'], 'audit_entries_subject');
            $rows->index(['tenant', 'actor_id', 'sequence'], 'audit_entries_actor');
            $rows->index(['tenant', 'started_by', 'sequence'], 'audit_entries_starter');
            $rows->index(['tenant', 'occurred_at'], 'audit_entries_age');
        }, $ifNotExists);

        $schema->create(self::HEADS, static function (Table $rows): void {
            $rows->string('tenant', self::NAME)->primary();
            $rows->bigInteger('sequence');
            $rows->string('hash', 64);
            $rows->bigInteger('pruned_through');
            $rows->string('anchor', 64);
            $rows->string('anchor_mac', 64);
        }, $ifNotExists);
    }

    public static function drop(Schema $schema): void
    {
        $schema->drop(self::HEADS);
        $schema->drop(self::ENTRIES);
    }
}
