<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Attributes\Schedule;

/**
 * Once a day, checks every active custom domain's DNS: "Custom domains are
 * re-verified daily ... and deactivated if their DNS no longer points at
 * the platform" (tinvar-spec.md). An application with custom domains lists
 * it among its scheduled tasks and gives its container the
 * CustomDomainVerification to check with, as it does RevalidateStaticPages
 * and its StaticPages.
 *
 * As every scheduled task does, it runs with no tenant, as nobody and in no
 * panel: the records are the application's, read across tenants by the
 * application's own CustomDomains. Each domain's check is bounded by its
 * verifier's timeout, so one slow domain costs its own seconds and the rest
 * are still checked. A domain whose check or record threw is left for the
 * next run, and those failures are thrown together at the end, for the
 * scheduler to report. A domain deactivated is not a failure: it is what
 * the task is for.
 *
 * At 03:41 UTC, off the hour other daily work crowds.
 */
#[Schedule('41 3 * * *')]
final class VerifyCustomDomains
{
    public function __invoke(CustomDomainVerification $verification): void
    {
        $failures = $verification->verifyDue()['failures'];

        if ($failures !== []) {
            $reasons = [];
            foreach ($failures as $host => $e) {
                $reasons[] = "{$host}: " . $e::class . ': ' . $e->getMessage();
            }

            throw new \RuntimeException('Some custom domains were not checked: ' . implode('; ', $reasons));
        }
    }
}
