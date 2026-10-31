<?php

declare(strict_types=1);

/**
 * `tinvar audit`'s verdict on what the advisory databases answered, held
 * to audit-exceptions.json (ADR 0137): each exception reviewed, dated, and
 * matching an advisory still reported -- with nothing fetched.
 */

namespace Tinvar\Tests\Packages\Advisories;

use Tinvar\Packages\Advisories;
use Tinvar\Support\PrivateDirectory;

beforeEach(function () {
    $this->root = PrivateDirectory::create('tinvar-audit');
    $this->today = new \DateTimeImmutable('2026-10-31', new \DateTimeZone('UTC'));
});

afterEach(function () {
    PrivateDirectory::remove($this->root);
});

/** @param list<array<string, string>> $exceptions */
function exceptions(string $root, array $exceptions): void
{
    file_put_contents("{$root}/" . Advisories::FILE, json_encode(['exceptions' => $exceptions], JSON_THROW_ON_ERROR));
}

/** An `npm audit --json` report: deepmerge's advisory, as npm lists it, and $more. */
function npm(array $more = []): string
{
    return json_encode(['auditReportVersion' => 2, 'vulnerabilities' => [
        'deepmerge' => ['name' => 'deepmerge', 'severity' => 'low', 'via' => [[
            'source' => 1243505, 'name' => 'deepmerge', 'dependency' => 'deepmerge',
            'title' => 'deepmerge mergeObject() allows prototype poisoning of returned objects',
            'url' => 'https://github.com/advisories/GHSA-gcqx-w48m-w2h4', 'severity' => 'low', 'range' => '>=4.2.0 <=4.3.1',
        ]]],
        // Listed for depending on it: its reach, not another advisory.
        'html-to-text' => ['name' => 'html-to-text', 'severity' => 'low', 'via' => ['deepmerge']],
        ...$more,
    ]], JSON_THROW_ON_ERROR);
}

function minimatch(): array
{
    return ['minimatch' => ['name' => 'minimatch', 'severity' => 'moderate', 'via' => [[
        'source' => 1244012, 'name' => 'minimatch', 'title' => 'minimatch has potential denial of service via globparts amplification',
        'url' => 'https://github.com/advisories/GHSA-g3hv-75wg-m249', 'severity' => 'moderate',
    ]]]];
}

function deepmerge(string $expires = '2027-01-31'): array
{
    return ['advisory' => 'GHSA-gcqx-w48m-w2h4', 'ecosystem' => 'npm', 'package' => 'deepmerge', 'reason' => 'html-to-text merges only its own options.', 'expires' => $expires];
}

const CLEAN_COMPOSER = '{"advisories": [], "abandoned": [], "filter": []}';

it('passes a low npm advisory, as the moderate gate did, and fails a moderate one', function () {
    $advisories = new Advisories($this->root, $this->today);

    expect($advisories->judge([CLEAN_COMPOSER, 0], [npm(), 0])['failures'])->toBe([])
        ->and($advisories->judge([CLEAN_COMPOSER, 0], [npm(minimatch()), 1])['failures'])->toHaveCount(1)
        ->and($advisories->judge(null, [npm(minimatch()), 1])['failures'][0])->toContain('GHSA-g3hv-75wg-m249');
});

it('excuses an advisory a reviewed exception names, and says so', function () {
    exceptions($this->root, [deepmerge(), ['advisory' => 'ghsa-g3hv-75wg-m249', 'ecosystem' => 'npm', 'package' => 'minimatch', 'reason' => 'Dev only.', 'expires' => '2026-12-01']]);

    $verdict = new Advisories($this->root, $this->today)->judge([CLEAN_COMPOSER, 0], [npm(minimatch()), 1]);

    expect($verdict['failures'])->toBe([])
        ->and($verdict['excused'])->toHaveCount(2)
        ->and($verdict['excused'][0])->toContain('excused until 2027-01-31: html-to-text merges only its own options.');
});

it('fails an exception past its day, or more than a year out', function () {
    exceptions($this->root, [deepmerge('2026-10-30')]);
    $expired = new Advisories($this->root, $this->today)->judge(null, [npm(), 0])['failures'];
    exceptions($this->root, [deepmerge('2028-01-01')]);
    $distant = new Advisories($this->root, $this->today)->judge(null, [npm(), 0])['failures'];
    exceptions($this->root, [deepmerge('2026-10-31')]);
    $today = new Advisories($this->root, $this->today)->judge(null, [npm(), 0])['failures'];

    expect($expired)->toHaveCount(1)
        ->and($expired[0])->toContain('expired on 2026-10-30')
        ->and($distant[0])->toContain('more than 366 days out')
        ->and($today)->toBe([]);
});

it('fails an exception that matches no advisory reported, or another package\'s', function () {
    exceptions($this->root, [deepmerge(), [...deepmerge(), 'package' => 'lodash']]);

    $failures = new Advisories($this->root, $this->today)->judge(null, [json_encode(['vulnerabilities' => []]), 0])['failures'];

    expect($failures)->toHaveCount(2)
        ->and($failures[0])->toContain('matches no advisory the databases reported: remove it');
});

it('fails an exception missing what a reviewer reads', function (array $entry, string $says) {
    exceptions($this->root, [$entry]);

    expect(new Advisories($this->root, $this->today)->judge(null, [npm(), 0])['failures'][0])->toContain($says);
})->with([
    'no reason' => [[...deepmerge(), 'reason' => ' '], 'has no reason'],
    'no expiry' => [array_diff_key(deepmerge(), ['expires' => true]), 'has no expires'],
    'not a date' => [[...deepmerge(), 'expires' => '31/01/2027'], 'not a YYYY-MM-DD date'],
    'no such day' => [[...deepmerge(), 'expires' => '2027-02-30'], 'not a YYYY-MM-DD date'],
    'another ecosystem' => [[...deepmerge(), 'ecosystem' => 'pypi'], 'the ecosystem is composer or npm'],
]);

it('fails a database that did not answer', function () {
    $advisories = new Advisories($this->root, $this->today);

    expect($advisories->judge(['', 1], null)['failures'][0])->toContain('composer audit gave no report')
        ->and($advisories->judge(null, ['{"error": {"summary": "request to https://registry.npmjs.org failed"}}', 1])['failures'][0])
        ->toContain('npm audit gave no report: request to https://registry.npmjs.org failed');
});

it('fails every Composer advisory unexcused, matched by any of its ids, and abandoned packages where composer fails them', function () {
    $composer = json_encode(['advisories' => ['acme/http' => [[
        'advisoryId' => 'PKSA-1234-abcd', 'packageName' => 'acme/http', 'title' => 'Header injection', 'cve' => 'CVE-2026-0001',
        'severity' => 'low', 'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-aaaa-bbbb-cccc']],
    ]]], 'abandoned' => []], JSON_THROW_ON_ERROR);

    expect(new Advisories($this->root, $this->today)->judge([$composer, 1], null)['failures'])->toHaveCount(1);

    exceptions($this->root, [['advisory' => 'CVE-2026-0001', 'ecosystem' => 'composer', 'package' => 'acme/http', 'reason' => 'Unreached.', 'expires' => '2027-01-01']]);
    expect(new Advisories($this->root, $this->today)->judge([$composer, 1], null)['failures'])->toBe([]);

    $abandoned = '{"advisories": [], "abandoned": {"acme/old": null}}';
    expect(new Advisories($this->root, $this->today)->judge([$abandoned, 2], null)['failures'])->toContain('composer audit found abandoned packages: acme/old.')
        ->and(new Advisories($this->root, $this->today)->judge([$abandoned, 0], null)['failures'])->toContain(
            Advisories::FILE . ': CVE-2026-0001 on composer acme/http matches no advisory the databases reported: remove it.',
        );
});

// The workspace's own file: every entry well formed and in force today.
it('holds the workspace\'s and the skeleton\'s exceptions to the rules', function (string $root) {
    expect(new Advisories($root, new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->exceptions()[1])->toBe([]);
})->with([
    'workspace' => \dirname(__DIR__, 4),
    'skeleton' => \dirname(__DIR__, 3) . '/skeleton',
]);
