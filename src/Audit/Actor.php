<?php

declare(strict_types=1);

namespace Tinvar\Audit;

/**
 * Who an audit entry says made a change (ADR 0156): the kind of actor, its
 * id, and for a user acting as someone else, the one doing it; for a job,
 * which job it was and who started the work it is part of; for a user
 * signed in by an API token, which token.
 */
final readonly class Actor
{
    /**
     * @param string|null $id the user's id, the service's name, or the user a job runs as; null for none
     * @param string|null $impersonator who was signed in as the user, where the application says one was
     * @param string|null $job the job's class, for a change a job made
     * @param string|null $jobId that job's id on the queue
     * @param string|null $startedBy who started the job's work: the user signed in where it was dispatched
     * @param string|null $apiToken the id of the API token the user acted through (ADR 0159) -- for a
     *        job, the one the user who started its work acted through; null for a session's
     *        request, or anything else
     */
    public function __construct(
        public ActorKind $kind,
        public ?string $id = null,
        public ?string $impersonator = null,
        public ?string $job = null,
        public ?string $jobId = null,
        public ?string $startedBy = null,
        public ?string $apiToken = null,
    ) {}
}
