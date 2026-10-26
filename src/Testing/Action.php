<?php

declare(strict_types=1);

namespace Tinvar\Testing;

/**
 * `Action::fake(...)`, as the spec writes it: Query::fake()'s pair, for
 * actions. See Query for why it is spelled as a class in the testing layer.
 */
final class Action
{
    /**
     * Action $action -- [Class::class, 'method'] or its name -- runs $body in
     * place of its own for the rest of the test, however it is called: a
     * direct call, a form posted to it, a script's call.
     *
     * Its tenant, panel stack, middleware, model resolution, #[Authorize] and
     * validation all run first, and a Precognition call still stops before
     * the body. $body is handed the arguments it names, as the body would
     * have been bound, and returns an ActionResult: what it says to
     * revalidate, redirect to or flash is answered as the body's would be,
     * through the encoder.
     *
     * @param array{0: class-string, 1: string}|string $action
     */
    public static function fake(array|string $action, \Closure $body): void
    {
        $kernel = Testing::client()->app->kernel();

        Query::fakes()->fakeAction(Client::actionName($kernel, $action), $body);
    }
}
