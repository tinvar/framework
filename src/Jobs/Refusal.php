<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * Why a job was refused for good, and where it was dispatched from: what a
 * job's refused() hook is handed (ADR 0107).
 *
 * The hook runs because nothing else would: a refused job never reaches
 * its failed(), which runs in the job's context, and that context is
 * exactly what no longer checks out. So it runs in none of it -- in the
 * tenant the payload sealed, with nobody signed in and no panel -- and is
 * told the rest here, from the payload whose MAC verified: the dispatcher's
 * panel and user as they were sealed, never as anything says now. Enough to
 * find a record started there and say it will not run; not enough to act
 * as anyone.
 */
final readonly class Refusal
{
    public function __construct(
        // JobRefused::$reason: 'epoch_changed', 'membership', 'user_missing'...
        public string $reason,
        // The tenant the job was dispatched in: the one the hook runs as.
        public string $tenant,
        // The panel it was dispatched in, or null for none.
        public ?string $panel,
        // The user it was dispatched by, or null for nobody.
        public ?string $user,
    ) {}
}
