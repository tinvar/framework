<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Testing\Fakes\BodyFakes;

/**
 * `Query::fake(RackStats::class, fn () => ...)`, as the spec writes it.
 *
 * Spelled as a pair of classes in the testing layer -- this one and Action --
 * rather than as methods on Client, because the spec's call names the query
 * and nothing else: Client is immutable, and a fake is not this client's but
 * the application's, answering every caller that reaches the query in this
 * test. Never on Tinvar\Attributes\Query, the attribute, which is production
 * code and keeps no state of its own.
 *
 * The fake is installed in the BodyFakes the application under test
 * (Testing::use()) was built with, and removed by Testing::forget().
 */
final class Query
{
    /**
     * Query $query -- its class or its name -- answers with $body for the rest
     * of the test, in page tests, renders and direct calls alike.
     *
     * Its allowlist, panel, model resolution and #[Authorize] all run first,
     * and $body runs read-only where the body would, handed the arguments it
     * names. What it returns is encoded as the body's value is, so a fake
     * returning a model fails as production would.
     */
    public static function fake(string $query, \Closure $body): void
    {
        $kernel = Testing::client()->app->kernel();

        self::fakes()->fakeQuery(Client::queryName($kernel, $query), $body);
    }

    /** The application under test's fakes, or why there are none. */
    public static function fakes(): BodyFakes
    {
        $fakes = Testing::client()->app->kernel()->standIns;
        if (!$fakes instanceof BodyFakes) {
            throw new \LogicException(
                'The application under test was built without fakes for its queries and actions: build it with a '
                . BodyFakes::class . ' as its StandIns.',
            );
        }

        return $fakes;
    }
}
