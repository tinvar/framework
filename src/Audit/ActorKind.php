<?php

declare(strict_types=1);

namespace Tinvar\Audit;

/**
 * What kind of thing an audit entry says made a change (ADR 0156). The
 * value is what the entry's `actor_type` column holds.
 */
enum ActorKind: string
{
    /** A signed-in user, in a request: the entry's actor id is theirs. */
    case User = 'user';

    /**
     * Another system signed in as itself (Tinvar\Services\Service): the id
     * is its service name. Or a tenant's API token acting for its tenant
     * (ADR 0193): the id is `tenant-token:<id>`, and the entry names the
     * token as its API token.
     */
    case Service = 'service';

    /** A job on the queue: the id is the user it runs as, if any; the entry names the job and its starter. */
    case Job = 'job';

    /** A request with nobody signed in: a sign-in, a public form. */
    case Guest = 'guest';

    /** Anything else: a command, a scheduled task, a seeder. */
    case System = 'system';
}
