<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * A write attempted inside a read-only context.
 *
 * tinvar-spec.md, "Safe reads": "Loaders and queries run in a read-only
 * transaction. save(), job dispatch and event dispatch throw inside them,
 * because hovering a link runs them."
 *
 * That last clause is the whole reason. Prefetch-on-hover means a loader runs
 * for a page nobody has decided to visit, so a loader that writes turns a
 * mouse movement into a mutation.
 */
final class ReadOnlyViolation extends \RuntimeException
{
    public static function write(string $what, string $where): self
    {
        return new self(
            "{$what} is not allowed inside {$where}, which runs read-only. "
            . 'Hovering a link runs a loader, so a write here happens without anyone choosing it. '
            . 'Move the write into an #[Action].',
        );
    }
}
