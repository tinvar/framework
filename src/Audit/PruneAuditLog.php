<?php

declare(strict_types=1);

namespace Tinvar\Audit;

use Tinvar\Attributes\Schedule;

/**
 * Every night, takes away the audit log's entries older than its retention
 * (ADR 0151): a year unless TINVAR_AUDIT_RETENTION_DAYS says otherwise
 * (AuditConfig), across every tenant, oldest first, a bounded number of
 * batches a run so a log never pruned is caught up over a few nights
 * rather than by one statement holding the write lock.
 *
 * A task an application lists among its scheduled tasks beside its
 * AuditLog. What is taken is a whole prefix of each tenant's log, and the
 * tenant's anchor moves with it, so `tinvar audit:verify` still walks what
 * is left.
 */
#[Schedule('17 3 * * *')]
final class PruneAuditLog
{
    public function __invoke(AuditLog $audit): void
    {
        $audit->prune();
    }
}
