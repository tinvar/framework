<?php

declare(strict_types=1);

namespace Tinvar\Console;

/**
 * How a command is placed in a tenant: what tinvar/tenancy registers with
 * the console, so a command's `#[TenantFrom(...)]` runs without the
 * framework knowing what a tenant source is (ADR 0056).
 *
 * Without one, a command declaring a tenant source is refused when the
 * console is built: it would otherwise run with no tenant while its author
 * believed it ran as one. A command that declares none still refuses
 * `--tenant`, so nobody passes one believing a command is scoped when it is
 * not.
 */
interface CommandTenants
{
    /**
     * Refuses, when a command is registered, a tenant source a command
     * cannot run under: one that reads a request, which a command has not.
     *
     * @param class-string $command
     * @throws \InvalidArgumentException
     */
    public function checkCommand(string $command, string $source): void;

    /**
     * The tenant a command declaring $source runs as, from what its
     * `--tenant=` named: checked to be one of the application's tenants.
     *
     * @param string $name the command's name, for what a refusal says
     * @param string|null $named what `--tenant=` said, or null for nothing
     * @throws \InvalidArgumentException for no tenant named, or one there is not
     */
    public function commandTenant(string $name, string $source, ?string $named): string;
}
