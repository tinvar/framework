<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Attributes\Panel;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelRegistry;

/**
 * The panel a class of work that is not a request -- a job, a command, a
 * scheduled task, a webhook -- declares: by its #[Panel], or else by its
 * namespace, with what the panel's definition requires of a membership.
 *
 * The same rule entry points follow (EntryContext::panelFor()), for the
 * spec's "every non-HTTP context ... has a defined panel". A panel declared
 * and not defined is refused, as it is for a page: it would be a context
 * that looked narrowed and was not.
 */
final class DeclaredPanel
{
    /** @param class-string $class */
    public static function of(string $class, ?PanelRegistry $registry): ?PanelBinding
    {
        $binding = PanelBinding::of(Panel::declaredOn($class));
        if ($binding === null) {
            $byNamespace = $registry?->panelOf($class);
            $binding = $byNamespace === null ? null : new PanelBinding($byNamespace);
        }
        if ($binding === null) {
            return null;
        }

        $definition = $registry?->definition($binding->name) ?? throw new \LogicException(
            "{$class} is in panel '{$binding->name}', which the application does not define.",
        );

        return $binding->within($definition);
    }
}
