<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor;

use Tinvar\Console\Doctor\Checks\BuildCheck;
use Tinvar\Console\Doctor\Checks\ConfigCheck;
use Tinvar\Console\Doctor\Checks\DatabaseCheck;
use Tinvar\Console\Doctor\Checks\HostsCheck;
use Tinvar\Console\Doctor\Checks\MailCheck;
use Tinvar\Console\Doctor\Checks\NodeCheck;
use Tinvar\Console\Doctor\Checks\PermissionsCheck;
use Tinvar\Console\Doctor\Checks\PhpCheck;
use Tinvar\Console\Doctor\Checks\ProductionCheck;
use Tinvar\Console\Doctor\Checks\ProxiesCheck;
use Tinvar\Console\Doctor\Checks\RendererCheck;
use Tinvar\Console\Doctor\Checks\SecretsCheck;

/**
 * `tinvar doctor` (ADR 0157): every check an application needs to boot and
 * serve safely, run together, and every problem reported at once, each
 * with the exact fix.
 *
 *     bin/tinvar doctor          # report; exit 1 on any problem
 *     bin/tinvar doctor --fix    # do the safe fixes first, then report
 *
 * Each check runs the framework's own boot-time check where there is one --
 * the config classes' load, TrustedProxies, Domains, MailTransports, the
 * build profile, discovery's manifest, the renderer health check, DevMode --
 * so the doctor says what the boot would, before the boot does.
 *
 * `--fix` does only what is safe: a missing dev secret generated into its
 * file outside the project, and the pending migrations run -- both refused
 * where the environment says production or staging, where a secret belongs
 * in the platform's secret store and a migration in the deploy.
 */
final class Doctor
{
    /** @var list<DoctorCheck> */
    private readonly array $checks;

    /** @param list<DoctorCheck>|null $checks every check, in the order reported; the framework's by default */
    public function __construct(?array $checks = null)
    {
        $this->checks = $checks ?? self::checks();
    }

    /**
     * The framework's checks, in the order a person fixes them: the
     * machine, the secrets, the config, the hosts and the proxies, mail,
     * the database, the build, the renderers, the files, and production.
     *
     * @return list<DoctorCheck>
     */
    public static function checks(): array
    {
        return [
            new PhpCheck(),
            new NodeCheck(),
            new SecretsCheck(),
            new ConfigCheck(),
            new ProxiesCheck(),
            new HostsCheck(),
            new MailCheck(),
            new DatabaseCheck(),
            new BuildCheck(),
            new RendererCheck(),
            new PermissionsCheck(),
            new ProductionCheck(),
        ];
    }

    /**
     * Every check's findings, by title. A check that throws is a problem of
     * its own, never the end of the run.
     *
     * @return list<array{string, list<Finding>}>
     */
    public function examine(DoctorContext $context): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            try {
                $findings = $check->run($context);
            } catch (\Throwable $e) {
                $findings = [Finding::problem(
                    "The check itself failed: {$e->getMessage()}",
                    'Fix what it names, and run the doctor again; a check that fails on a working app is a bug in the framework.',
                )];
            }
            $results[] = [$check->title(), $findings === [] ? [Finding::passed('Nothing to check.')] : $findings];
        }

        return $results;
    }

    /**
     * The command: report, after the safe fixes where $fix. Exit 1 on any
     * problem left, 0 otherwise -- warnings included.
     *
     * @param \Closure(string): void $out
     */
    public function run(DoctorContext $context, bool $fix, \Closure $out): int
    {
        $results = $this->examine($context);

        if ($fix) {
            $fixes = [];
            foreach ($results as [, $findings]) {
                foreach ($findings as $finding) {
                    if ($finding->safeFix !== null && $finding->severity !== Severity::Passed) {
                        $fixes[] = $finding->safeFix;
                    }
                }
            }
            if ($fixes === []) {
                $out("Nothing for --fix to do safely.\n");
            }
            foreach ($fixes as $safe) {
                try {
                    $out('fixed: ' . ($safe->apply)() . "\n");
                } catch (\Throwable $e) {
                    $out("could not {$safe->describe}: {$e->getMessage()}\n");
                }
            }
            if ($fixes !== []) {
                $out("\n");
                $results = $this->examine($context);
            }
        }

        $out(self::report($results, $context->console));

        return self::count($results, Severity::Problem) > 0 ? 1 : 0;
    }

    /**
     * The findings as a person reads them: each check's heading, each
     * finding under it with its fix, and a last line counting them.
     *
     * @param list<array{string, list<Finding>}> $results
     */
    public static function report(array $results, string $console = 'bin/tinvar'): string
    {
        $lines = [];
        $fixable = 0;
        foreach ($results as [$title, $findings]) {
            $lines[] = $title;
            foreach ($findings as $finding) {
                $lines[] = \sprintf('  %-8s %s', $finding->severity->value, self::indent($finding->message, 11));
                if ($finding->fix !== null && $finding->severity !== Severity::Passed && $finding->severity !== Severity::Skipped) {
                    $lines[] = '           fix: ' . self::indent($finding->fix, 16);
                }
                if ($finding->safeFix !== null && $finding->severity !== Severity::Passed) {
                    ++$fixable;
                }
            }
        }
        $problems = self::count($results, Severity::Problem);
        $warnings = self::count($results, Severity::Warning);
        $lines[] = '';
        $lines[] = match (true) {
            $problems === 0 && $warnings === 0 => 'No problems.',
            default => \sprintf('%d %s, %d %s.', $problems, $problems === 1 ? 'problem' : 'problems', $warnings, $warnings === 1 ? 'warning' : 'warnings')
                . ($fixable > 0 ? " `{$console} doctor --fix` fixes {$fixable} of them safely." : ''),
        };

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array{string, list<Finding>}> $results */
    private static function count(array $results, Severity $severity): int
    {
        $count = 0;
        foreach ($results as [, $findings]) {
            foreach ($findings as $finding) {
                $count += $finding->severity === $severity ? 1 : 0;
            }
        }

        return $count;
    }

    private static function indent(string $text, int $by): string
    {
        return str_replace("\n", "\n" . str_repeat(' ', $by), $text);
    }
}
