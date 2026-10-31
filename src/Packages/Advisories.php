<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * What `tinvar audit` makes of the advisory databases' answers: `composer
 * audit --format=json` and `npm audit --json`, held to the project's
 * reviewed exceptions in audit-exceptions.json (ADR 0137).
 *
 * Every Composer advisory fails, and every npm advisory of moderate
 * severity or worse -- the gates the two commands kept before -- unless an
 * exception names it: the advisory's id (a GHSA id, a CVE or Packagist's
 * PKSA id), the ecosystem and the package it was reported against, why it
 * does not reach the application, and the day it expires:
 *
 *     {"exceptions": [{
 *         "advisory": "GHSA-gcqx-w48m-w2h4",
 *         "ecosystem": "npm",
 *         "package": "deepmerge",
 *         "reason": "Merges only options html-to-text's own code sets.",
 *         "expires": "2027-01-31"
 *     }]}
 *
 * An exception is a decision with a date on it, as .grype.yaml's are held
 * to a version: one past its day fails, one more than a year out is
 * refused, and one that matches no advisory either database reported fails
 * too, so a fixed advisory takes its exception out with it. A database
 * that did not answer fails: nothing heard is not nothing found.
 */
final readonly class Advisories
{
    public const string FILE = 'audit-exceptions.json';

    /** The furthest out an exception may expire: it is reviewed again by then. */
    public const int LONGEST_DAYS = 366;

    /** The npm severities that fail unexcused, as `npm audit --audit-level=moderate` failed on. */
    private const array NPM_FAILING = ['moderate', 'high', 'critical'];

    /** Composer audit's exit status for an abandoned package (Composer\Advisory\Auditor::STATUS_ABANDONED). */
    private const int COMPOSER_ABANDONED = 2;

    public function __construct(
        private string $root,
        private \DateTimeImmutable $today,
    ) {}

    /**
     * The verdict on what the databases said: one line per failure, and one
     * per advisory an exception excused, for the log.
     *
     * @param array{string, int}|null $composer composer audit's JSON and exit status, or null where there is no composer.lock
     * @param array{string, int}|null $npm npm audit's JSON and exit status, or null where there is no package-lock.json
     * @return array{failures: list<string>, excused: list<string>}
     */
    public function judge(?array $composer, ?array $npm): array
    {
        [$exceptions, $failures] = $this->exceptions();
        $excused = [];
        $used = [];

        $reported = [];
        if ($composer !== null) {
            [$advisories, $problems] = self::composer(...$composer);
            $reported = [...$reported, ...$advisories];
            $failures = [...$failures, ...$problems];
        }
        if ($npm !== null) {
            [$advisories, $problems] = self::npm($npm[0]);
            $reported = [...$reported, ...$advisories];
            $failures = [...$failures, ...$problems];
        }

        foreach ($reported as $advisory) {
            $by = null;
            foreach ($exceptions as $index => $exception) {
                if ($exception['ecosystem'] === $advisory['ecosystem'] && $exception['package'] === $advisory['package']
                    && array_filter($advisory['ids'], static fn (string $id): bool => strcasecmp($id, $exception['advisory']) === 0) !== []) {
                    $by = $index;

                    break;
                }
            }
            $name = "{$advisory['ecosystem']} {$advisory['package']} " . implode(' / ', $advisory['ids']) . " ({$advisory['severity']}): {$advisory['title']}";
            if ($by !== null) {
                $used[$by] = true;
                $excused[] = "{$name} -- excused until {$exceptions[$by]['expires']}: {$exceptions[$by]['reason']}";
            } elseif ($advisory['fails']) {
                $failures[] = $name;
            }
        }

        foreach ($exceptions as $index => $exception) {
            if (!isset($used[$index])) {
                $failures[] = self::FILE . ": {$exception['advisory']} on {$exception['ecosystem']} {$exception['package']} matches no advisory "
                    . 'the databases reported: remove it.';
            }
        }

        return ['failures' => $failures, 'excused' => $excused];
    }

    /**
     * The exceptions in force, and what is wrong with the file: an entry
     * expired, too far out, or missing what a reviewer reads.
     *
     * @return array{list<array{advisory: string, ecosystem: string, package: string, reason: string, expires: string}>, list<string>}
     */
    public function exceptions(): array
    {
        $path = "{$this->root}/" . self::FILE;
        if (!is_file($path)) {
            return [[], []];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!\is_array($decoded) || !\is_array($decoded['exceptions'] ?? null) || !array_is_list($decoded['exceptions'])) {
            return [[], [self::FILE . ' is not {"exceptions": [...]}.']];
        }

        $exceptions = [];
        $problems = [];
        $latest = $this->today->modify('+' . self::LONGEST_DAYS . ' days');
        foreach ($decoded['exceptions'] as $n => $entry) {
            $where = self::FILE . ' exception ' . ($n + 1);
            $fields = [];
            foreach (['advisory', 'ecosystem', 'package', 'reason', 'expires'] as $field) {
                $value = \is_array($entry) ? ($entry[$field] ?? null) : null;
                if (!\is_string($value) || trim($value) === '') {
                    $problems[] = "{$where} has no {$field}: an exception names the advisory, the ecosystem and package, why, and when it expires.";

                    continue 2;
                }
                $fields[$field] = trim($value);
            }
            $where .= " ({$fields['advisory']} on {$fields['package']})";
            if (!\in_array($fields['ecosystem'], ['composer', 'npm'], true)) {
                $problems[] = "{$where} is for '{$fields['ecosystem']}': the ecosystem is composer or npm.";

                continue;
            }
            $expires = \DateTimeImmutable::createFromFormat('!Y-m-d', $fields['expires'], new \DateTimeZone('UTC'));
            if ($expires === false || $expires->format('Y-m-d') !== $fields['expires']) {
                $problems[] = "{$where} expires on '{$fields['expires']}', which is not a YYYY-MM-DD date.";

                continue;
            }
            if ($expires < $this->today) {
                $problems[] = "{$where} expired on {$fields['expires']}: review it again, and give it a new date or remove it.";

                continue;
            }
            if ($expires > $latest) {
                $problems[] = "{$where} expires on {$fields['expires']}, more than " . self::LONGEST_DAYS . ' days out: an exception is reviewed again within a year.';

                continue;
            }
            /** @var array{advisory: string, ecosystem: string, package: string, reason: string, expires: string} $fields */
            $exceptions[] = $fields;
        }

        return [$exceptions, $problems];
    }

    /**
     * @return array{list<array{ecosystem: string, package: string, ids: list<string>, severity: string, title: string, fails: bool}>, list<string>}
     */
    private static function composer(string $json, int $status): array
    {
        $decoded = json_decode($json, true);
        if (!\is_array($decoded) || !\is_array($decoded['advisories'] ?? null)) {
            return [[], ["composer audit gave no report (exit {$status}): it needs the network."]];
        }

        $advisories = [];
        foreach ($decoded['advisories'] as $package => $list) {
            foreach (\is_array($list) ? $list : [] as $advisory) {
                if (!\is_array($advisory)) {
                    continue;
                }
                $ids = [];
                foreach ([$advisory['advisoryId'] ?? null, $advisory['cve'] ?? null] as $id) {
                    if (\is_string($id) && $id !== '') {
                        $ids[] = $id;
                    }
                }
                foreach (\is_array($advisory['sources'] ?? null) ? $advisory['sources'] : [] as $source) {
                    if (\is_array($source) && \is_string($source['remoteId'] ?? null) && $source['remoteId'] !== '') {
                        $ids[] = $source['remoteId'];
                    }
                }
                $advisories[] = [
                    'ecosystem' => 'composer',
                    'package' => \is_string($advisory['packageName'] ?? null) ? $advisory['packageName'] : (string) $package,
                    'ids' => array_values(array_unique($ids)),
                    'severity' => \is_string($advisory['severity'] ?? null) ? $advisory['severity'] : 'unknown',
                    'title' => \is_string($advisory['title'] ?? null) ? $advisory['title'] : '',
                    'fails' => true,
                ];
            }
        }

        $problems = [];
        $abandoned = \is_array($decoded['abandoned'] ?? null) ? array_keys($decoded['abandoned']) : [];
        if (($status & self::COMPOSER_ABANDONED) !== 0 && $abandoned !== []) {
            $problems[] = 'composer audit found abandoned packages: ' . implode(', ', $abandoned) . '.';
        }

        return [$advisories, $problems];
    }

    /**
     * @return array{list<array{ecosystem: string, package: string, ids: list<string>, severity: string, title: string, fails: bool}>, list<string>}
     */
    private static function npm(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!\is_array($decoded) || !\is_array($decoded['vulnerabilities'] ?? null)) {
            $error = \is_array($decoded) && \is_array($decoded['error'] ?? null) && \is_string($decoded['error']['summary'] ?? null) ? ": {$decoded['error']['summary']}" : '';

            return [[], ["npm audit gave no report{$error}: it needs the network."]];
        }

        $advisories = [];
        foreach ($decoded['vulnerabilities'] as $vulnerability) {
            // A package that is only listed for depending on one is the
            // advisory's reach, not another advisory: `via` names it.
            foreach (\is_array($vulnerability) && \is_array($vulnerability['via'] ?? null) ? $vulnerability['via'] : [] as $via) {
                if (!\is_array($via) || !\is_string($via['name'] ?? null)) {
                    continue;
                }
                $url = \is_string($via['url'] ?? null) ? $via['url'] : '';
                $ids = preg_match('#/(GHSA(?:-[23456789cfghjmpqrvwx]{4}){3})$#i', $url, $match) === 1 ? [$match[1]] : [];
                if ($ids === [] && isset($via['source']) && (\is_int($via['source']) || \is_string($via['source']))) {
                    $ids = ['NPM-' . $via['source']];
                }
                $severity = \is_string($via['severity'] ?? null) ? $via['severity'] : 'unknown';
                $advisories[] = [
                    'ecosystem' => 'npm',
                    'package' => $via['name'],
                    'ids' => $ids,
                    'severity' => $severity,
                    'title' => \is_string($via['title'] ?? null) ? $via['title'] : '',
                    'fails' => \in_array($severity, self::NPM_FAILING, true) || $severity === 'unknown',
                ];
            }
        }

        return [$advisories, []];
    }
}
