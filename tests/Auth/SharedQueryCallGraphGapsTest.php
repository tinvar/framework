<?php

declare(strict_types=1);

/**
 * The shared-query rule's call graph, where it once failed open: a call it
 * resolved to the wrong method, or passed over without a word. Each query
 * here reaches a read of the user, and must end in a violation -- the read
 * found, or the call refused (ADR 0062).
 */

use Tinvar\Auth\BuildRules;
use Tinvar\Auth\Violation;
use Tinvar\Tests\Auth\Fixtures\CallGraphGaps as G;

require_once __DIR__ . '/Fixtures/callgraph.php';
require_once __DIR__ . '/Fixtures/callgraph-gaps.php';

/**
 * @param class-string $class
 * @return string every shared-query violation's message, one per line
 */
function sharedGapViolations(string $class): string
{
    return implode("\n", array_map(
        static fn (Violation $v): string => $v->message,
        array_values(array_filter(
            (new BuildRules())->check([$class]),
            static fn (Violation $v): bool => $v->rule === BuildRules::SHARED_QUERY_MUST_NOT_READ_THE_USER,
        )),
    ));
}

it('follows self:: in an inherited method to the class it is written in, not the query', function () {
    expect(sharedGapViolations(G\ThroughSelfInParent::class))
        ->toContain(G\Report::class . "::count(), which calls '->user('");
});

it('follows parent:: to the parent of the class it is written in', function () {
    expect(sharedGapViolations(G\ThroughParentInParent::class))
        ->toContain(G\Reads::class . "::read(), which calls '->user('");
});
