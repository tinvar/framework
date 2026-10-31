<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A class discovery leaves alone (ADR 0146): it is not registered as
 * whatever else it declares, and not held to registering at all.
 *
 *     #[Page('/preview'), NotRegistered(reason: 'mounted by the preview kernel alone')]
 *     final class Preview { ... }
 *
 * For the odd class an application keeps under its own namespace and
 * registers some other way -- by hand, in a Kernel of its own, or not yet.
 * The reason is required, as #[NoAuthorization]'s is: whoever reads the
 * class next sees why nothing finds it.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class NotRegistered
{
    public function __construct(public string $reason)
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('#[NotRegistered] needs a reason: say why discovery should leave the class alone.');
        }
    }
}
