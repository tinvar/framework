<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Attributes\Command as CommandName;
use Tinvar\Audit\AuditLog;

/**
 * `tinvar audit:verify [--tenant=ID]`: walks each tenant's audit log -- or
 * the one named -- and says whether every entry is in its place and as it
 * was written (ADR 0151). Exits 1 naming the first break of each log that
 * has one, by its place and what was wrong, never by what the entry holds;
 * 0 when every log holds.
 *
 * An operator's check, run with no tenant in force, so it reads every
 * tenant's head; it prints counts and places alone. Here rather than
 * beside the log, since commands are the console's: an application lists
 * it among its commands beside its AuditLog.
 */
#[CommandName('audit:verify', description: 'Check that every tenant\'s audit log is as it was written')]
final class VerifyAuditLog extends Command
{
    public function __construct(private readonly AuditLog $audit) {}

    public function handle(Input $input, Output $output): int
    {
        $named = $input->option('tenant');
        $tenants = $named === null ? $this->audit->tenants() : [$named];
        if ($tenants === []) {
            $output->line('No audit log has an entry yet.');

            return 0;
        }

        $broken = 0;
        foreach ($tenants as $tenant) {
            $result = $this->audit->verify($tenant);
            $label = $tenant === '' ? '(no tenant)' : $tenant;
            if ($result->intact) {
                $output->line("{$label}: {$result->entries} entries, intact");

                continue;
            }
            $broken++;
            $output->error("{$label}: broken at entry {$result->brokenAt}: {$result->problem}");
        }

        return $broken === 0 ? 0 : 1;
    }
}
