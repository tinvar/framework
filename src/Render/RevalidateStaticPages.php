<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Attributes\Schedule;

/**
 * Every minute, builds again each static page that is stale: older than its
 * revalidate interval, or taken out of service by an invalidated tag
 * (StaticPages). An application lists it among its scheduled tasks and
 * gives its container the StaticPages to build with.
 *
 * As every scheduled task does, it runs with no tenant, as nobody and in no
 * panel -- and the pages are rendered as a visitor with no session besides,
 * so nothing in them is anyone's. A page that fails is left as it was and
 * the rest are built; the failures are thrown together at the end, for the
 * scheduler to report.
 */
#[Schedule('* * * * *')]
final class RevalidateStaticPages
{
    public function __invoke(StaticPages $pages): void
    {
        $result = $pages->revalidate();

        if ($result['failed'] !== []) {
            $reasons = [];
            foreach ($result['failed'] as $path => $why) {
                $reasons[] = "{$path}: {$why}";
            }

            throw new \RuntimeException('Some static pages were not built again: ' . implode('; ', $reasons));
        }
    }
}
