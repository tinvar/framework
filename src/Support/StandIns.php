<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * What stands in for a query's or an action's body: a test's fakes (the
 * testing layer's BodyFakes), and nothing else.
 *
 * tinvar-spec.md, "Fakes": "`Query::fake(RackStats::class, fn () => ...)`
 * and `Action::fake(...)` isolate a page from its data." From its data, and
 * never from its checks: a stand-in is asked for only once a call has passed
 * everything before its body -- the allowlist, its panel, model resolution
 * and every #[Authorize], and for an action its validation too -- and what
 * it returns goes on as the body's would, through the encoder for a query,
 * through revalidation, redirect and flash for an action.
 *
 * Handed to the runners as a closure at construction, as QueryRunner's
 * `returned:` hook is, so neither keeps a registry of its own that anything
 * could reach. Production builds them with none: the Kernel refuses one
 * wherever the environment says production (DevMode::productionMarker()).
 */
interface StandIns
{
    /**
     * What answers in place of query $name's body, or null for its body. Called
     * as the body would be, with the call's arguments by name, read-only.
     */
    public function query(string $name): ?\Closure;

    /**
     * What runs in place of action $name's body, or null for its body. Called as
     * the body would be, with the arguments the body would be bound, and
     * returning an ActionResult.
     */
    public function action(string $name): ?\Closure;
}
