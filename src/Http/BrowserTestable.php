<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Auth\Authenticatable;

/**
 * What an application shows the browser test endpoint (BrowserTests), beside
 * what it shows the PHP testing layer.
 *
 * The endpoint asks; the application does it its own way. Seeding is the
 * application's scenarios -- named sets of rows a browser test starts from,
 * each saying which users it made. Signing in is TestableApplication's
 * signedIn(), the application's own sessions: a session made as signing in
 * makes one, the user's auth epoch moved on. And the clock is the
 * application's, which the framework does not have.
 *
 * The framework's, as TestableApplication is, though only the endpoint calls
 * it (ADR 0068): an application's Kernel implements it in production code,
 * and the endpoint is the testing layer's, which an image installed with
 * --no-dev does not have.
 */
interface BrowserTestable extends TestableApplication
{
    /**
     * Runs the scenario called $scenario, and says what it made: `users`,
     * the ids of the users the test may now sign in as -- the only ones the
     * endpoint signs in -- and anything else the test needs to find what was
     * made, which is handed to it as it is. No password or secret: the test
     * signs in through the endpoint.
     *
     * @param array<string, mixed> $input what the test asked for, as JSON decoded it
     * @return array<string, mixed> with `users`, a list of ids, which the endpoint holds it to
     * @throws \InvalidArgumentException for a scenario it does not have, or input it refuses
     */
    public function seed(string $scenario, array $input): array;

    /** The user a scenario's `users` named, or null when there is none now. */
    public function testUser(string $id): ?Authenticatable;

    /**
     * The application's clock, $offset seconds from the system's, from now
     * until it is told otherwise: set at the start of every request the run
     * serves, from the run's own record, so every process agrees.
     */
    public function moveClock(int $offset): void;
}
