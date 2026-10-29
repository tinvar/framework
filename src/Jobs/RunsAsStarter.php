<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * A job that runs as whoever dispatched it, when anyone did: the user and
 * auth epoch in force at dispatch are sealed with it, as #[Queue(asUser:
 * true)] seals them, and checked on every run -- but one dispatched with
 * nobody signed in runs as nobody, where an asUser job is refused.
 *
 * For work that carries its starter's context by nature rather than by
 * choice: a workflow started from a request runs each of
 * its steps as the person who started it, and one started by the schedule
 * runs as no one. "Every non-HTTP context ... has a defined panel,
 * serialized with the work" -- and, for these, the user too, so an epoch
 * moved between two steps ends the work rather than letting it go on under
 * a role its starter no longer holds.
 */
interface RunsAsStarter {}
