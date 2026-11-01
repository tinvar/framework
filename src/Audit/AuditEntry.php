<?php

declare(strict_types=1);

namespace Tinvar\Audit;

use Carbon\CarbonImmutable;

/**
 * One entry of a tenant's audit log, as AuditTrail reads it back (ADR 0150):
 * what happened, to what, by whom, where and when.
 *
 * Not Data: a loader maps what its page shows onto Data of its own, as it
 * does a model, so a page never shows more of the log than it chose to.
 */
final readonly class AuditEntry
{
    /**
     * @param int $sequence its place in its tenant's log, from 1: the cursor a page reads back from
     * @param string $event `created`, `updated` or `deleted` for a model's row (ModelChangeKind),
     *        `action` for an #[AuditedAction]'s run, or the event an application recorded
     * @param string|null $subjectType the model class the entry is about, if any
     * @param string|null $subjectId its key
     * @param string|null $action the action the change was made in, by name, if one was running
     * @param array<string, array{0: scalar|null, 1: scalar|null}> $changes for a model's row:
     *        field => [before, after], secrets `[redacted]`
     * @param array<string, mixed> $data what an action or an event recorded beside it
     */
    public function __construct(
        public string $id,
        public string $tenant,
        public int $sequence,
        public CarbonImmutable $occurredAt,
        public string $event,
        public ?string $subjectType,
        public ?string $subjectId,
        public Actor $actor,
        public ?string $action,
        public ?string $panel,
        public ?AuditRequest $request,
        public array $changes,
        public array $data,
    ) {}
}
