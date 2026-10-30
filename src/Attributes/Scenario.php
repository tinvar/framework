<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A named set of rows a test or a load test starts from, and the users it
 * makes, on a class implementing the scenarios' SeedsScenario (ADR 0090).
 *
 *     #[Scenario('customer')]
 *     final readonly class NewCustomer implements SeedsScenario { ... }
 *
 * A scenario's users are the only ones the browser test endpoint signs in
 * and the only ones `tinvar bench` signs in as. They are seeded only under a
 * profile that allows them -- staging and test, never production
 * (BuildProfile::allowsScenarios()) -- so no production database holds an
 * account whose password a load test knows.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Scenario
{
    public function __construct(public string $name)
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}\z/', $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not a scenario name: a lowercase word, or words joined by '-'.");
        }
    }
}
