<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Attributes\Panel;

/**
 * An entry point a module above the core compiles -- an export or an
 * import -- as the core checks it when the Kernel is built
 * (the entry checks): where it is, the panels it declares, and where
 * its tenant comes from.
 *
 * So the core holds a module's entry points to the rules every entry point
 * is held to without naming the module (ADR 0077).
 */
interface DeclaredEntryPoint
{
    /** @var list<Panel> */
    public array $panels { get; }

    /** The #[TenantFrom] source it reads its tenant from; null for none. */
    public ?string $tenantFrom { get; }

    /** The #[Domain] pattern it is held to (ADR 0149); null for none of its own. */
    public ?string $host { get; }

    /** @var class-string the class that declares it, whose namespace may name its panel */
    public string $class { get; }

    /** Where it is, for a refusal to name. */
    public function where(): string;
}
