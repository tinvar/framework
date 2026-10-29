<?php

declare(strict_types=1);

namespace Tinvar\Features;

/**
 * Where an application keeps what its flags are set to, per user, tenant and
 * panel: in code (FlagRules), in a table (DatabaseFlagValues), or in a
 * service of its own.
 *
 * Answers the value set for the most specific of the scope's parts that has
 * one -- the user, then the tenant, then the panel, then everyone -- or null
 * where none is set, and the flag's declared default applies. A store that
 * cannot answer throws: a flag read as its default because its store was
 * down is a feature switched on, or off, by an outage.
 */
interface FlagValues
{
    public function valueFor(string $flag, FlagScope $scope): ?bool;
}
