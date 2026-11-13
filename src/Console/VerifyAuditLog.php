<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Attributes\Command as CommandName;
use Tinvar\Audit\AuditLog;

/**
 * `tinvar audit:verify [--tenant=ID [--expect-head=SEQUENCE:HASH]]`: walks
 * each tenant's audit log -- or the one named -- and says whether every
 * entry is in its place and as it was written (ADR 0156). Exits 1 naming
 * the first break of each log that has one, by its place and what was
 * wrong, never by what the entry holds; 0 when every log holds.
 *
 * Every tenant with a head or with any entry is walked, so a log whose head
 * was deleted is a break rather than a tenant left out (ADR 0210). What the
 * chain cannot show alone -- its newest entries taken away with the head
 * put back to match, or the log deleted whole -- `--expect-head` shows: the
 * head an operator kept somewhere the database's writers cannot reach
 * (AuditLog::head()), which the named log must still reach.
 *
 * An operator's check, run with no tenant in force, so it reads every
 * tenant's head; it prints counts and places alone. It reads `--tenant`
 * itself (ChoosesTenant): it looks at that tenant's log, and runs as none.
 * Here rather than beside the log, since commands are the console's: an
 * application lists it among its commands beside its AuditLog.
 */
#[CommandName('audit:verify', description: 'Check that every tenant\'s audit log is as it was written')]
final class VerifyAuditLog extends Command implements ChoosesTenant
{
    public function __construct(private readonly AuditLog $audit) {}

    public function handle(Input $input, Output $output): int
    {
        if ($input->flag(Console::TENANT_OPTION)) {
            $output->error('Name the tenant whose log to check with --' . Console::TENANT_OPTION . '=ID.');

            return Console::USAGE;
        }
        $named = $input->option(Console::TENANT_OPTION);
        $expected = $input->option('expect-head');
        if ($expected !== null || $input->flag('expect-head')) {
            if (preg_match('/^([1-9][0-9]{0,18}):([0-9a-f]{16,128})\z/', (string) $expected, $m) !== 1) {
                $output->error('--expect-head is SEQUENCE:HASH, as AuditLog::head() gives them.');

                return Console::USAGE;
            }
            if ($named === null) {
                $output->error('--expect-head names one log\'s head: name its tenant with --' . Console::TENANT_OPTION . '=ID.');

                return Console::USAGE;
            }
            $expected = [(int) $m[1], $m[2]];
        }

        $tenants = $named === null ? $this->audit->tenants() : [$named];
        if ($tenants === []) {
            $output->line('No audit log has an entry yet.');

            return 0;
        }

        $broken = 0;
        foreach ($tenants as $tenant) {
            $result = $this->audit->verify($tenant);
            $label = $tenant === '' ? '(no tenant)' : $tenant;
            $reaches = $result->intact && \is_array($expected) ? $this->audit->reaches($tenant, ...$expected) : null;
            $failed = $result->intact ? $reaches : $result;
            if ($failed === null || $failed->intact) {
                $output->line("{$label}: {$result->entries} entries, intact" . ($reaches === null ? '' : ', at the head expected'));

                continue;
            }
            $broken++;
            $output->error("{$label}: broken at entry {$failed->brokenAt}: {$failed->problem}");
        }

        return $broken === 0 ? 0 : 1;
    }
}
