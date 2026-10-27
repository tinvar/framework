<?php

declare(strict_types=1);

/**
 * The stop budget and every wait derived from it, held to one another: the
 * container scripts' sums are StopBudget's, and the shop's manifests give
 * each container at least what its script will refuse to start without.
 *
 * Written in three languages and four files, they were kept in step by
 * hand before: ADR 0063's 70, 85 and 190 s.
 */

namespace Tinvar\Tests\Queue\StopBudgetManifests;

use Tinvar\Jobs\StopBudget;

const CONTAINER = __DIR__ . '/../../resources/container';
const SHOP = __DIR__ . '/../../../../examples/shop';

/** The first match of $pattern in $path, as a whole number. */
function number(string $path, string $pattern): int
{
    $text = file_get_contents($path);
    expect($text)->toBeString();
    expect(preg_match($pattern, (string) $text, $m))->toBe(1, "{$pattern} in {$path}");

    return (int) $m[1];
}

it('has the scripts check the budget StopBudget checks, and add what it adds', function () {
    foreach (['entrypoint', 'pod-php'] as $script) {
        $path = CONTAINER . "/{$script}";
        expect(number($path, '/stop_budget=\$\{TINVAR_STOP_BUDGET:-(\d+)\}/'))->toBe(StopBudget::DEFAULT)
            ->and(number($path, '/10#\$stop_budget < (\d+)/'))->toBe(StopBudget::MIN)
            ->and(number($path, '/10#\$stop_budget > (\d+)/'))->toBe(StopBudget::MAX);
    }

    $budget = 25;
    expect(number(CONTAINER . '/entrypoint', '/needs=\$\(\(stop_budget \+ (\d+)\)\)/'))->toBe(StopBudget::oneContainerGrace($budget) - $budget)
        ->and(number(CONTAINER . '/pod-php', '/needs=\$\(\(stop_budget \+ (\d+)\)\)/'))->toBe(StopBudget::phpContainerGrace($budget) - $budget)
        ->and(number(CONTAINER . '/supervise', '/serve_wait=\$\(\(stop_budget \+ (\d+)\)\)/'))->toBe(StopBudget::oneContainerServeWait($budget) - $budget);
});

it('gives each of the shop\'s containers the grace its budget needs, and tells it so', function () {
    // The one container.
    $budget = number(SHOP . '/compose.yaml', '/TINVAR_STOP_BUDGET: \$\{SHOP_STOP_BUDGET:-(\d+)\}/');
    $grace = number(SHOP . '/compose.yaml', '/stop_grace_period: \$\{SHOP_STOP_GRACE:-(\d+)\}s/');
    expect($grace)->toBeGreaterThanOrEqual(StopBudget::oneContainerGrace($budget))
        ->and(number(SHOP . '/compose.yaml', '/TINVAR_STOP_GRACE: \$\{SHOP_STOP_GRACE:-(\d+)\}/'))->toBe($grace);

    // PHP's container, beside the renderers'.
    $budget = number(SHOP . '/compose.pod.yaml', '/TINVAR_STOP_BUDGET: \$\{SHOP_STOP_BUDGET:-(\d+)\}/');
    $grace = number(SHOP . '/compose.pod.yaml', '/stop_grace_period: \$\{SHOP_STOP_GRACE:-(\d+)\}s/');
    expect($grace)->toBeGreaterThanOrEqual(StopBudget::phpContainerGrace($budget))
        ->and(number(SHOP . '/compose.pod.yaml', '/TINVAR_STOP_GRACE: \$\{SHOP_STOP_GRACE:-(\d+)\}/'))->toBe($grace);

    // The pod: its grace, and PHP's share of it once the preStop and the
    // renderers' drain after it are taken out.
    $manifest = SHOP . '/kubernetes/shop.yaml';
    $budget = number($manifest, '/name: TINVAR_STOP_BUDGET\s+value: "(\d+)"/');
    $pod = number($manifest, '/terminationGracePeriodSeconds: (\d+)/');
    $share = number($manifest, '/name: TINVAR_STOP_GRACE\s+value: "(\d+)"/');
    expect($pod)->toBeGreaterThanOrEqual(StopBudget::podGrace($budget))
        ->and($share)->toBeGreaterThanOrEqual(StopBudget::phpContainerGrace($budget))
        ->and($share)->toBeLessThanOrEqual($pod - 5 - 80);
});
