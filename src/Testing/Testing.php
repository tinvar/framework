<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Orm\LazyLoads;
use Tinvar\Testing\Fakes\BodyFakes;

/**
 * The application the current test is about, for the Pest-style functions
 * in test-functions.php: `get('/orders')`, `actingAs($customer)->action(...)`,
 * `travel(5)->minutes()`.
 *
 * Set by each test, in a beforeEach, with use(); the functions refuse to run
 * before it is, rather than reaching an application some earlier test left.
 * A test that would rather hold its client itself takes the one use()
 * returns and never touches the functions.
 *
 * Load the functions once, from the suite's Pest.php: Testing::functions().
 */
final class Testing
{
    private static ?Client $client = null;

    private static ?TestClock $clock = null;

    /**
     * Makes $app the application the functions call, with the renderer pool
     * render() goes through and the clock travel() moves, when it has them.
     *
     * The test starts with no query or action faked, whatever an earlier one
     * left in an application it shares, and with the application's lazy
     * relation loads recorded, for assertNoLazyLoadsOutsideBatch().
     */
    public static function use(TestableApplication $app, ?RendererPool $renderer = null, ?TestClock $clock = null): Client
    {
        $client = new Client($app, $renderer);
        self::$clock = $clock;
        self::$client = $client;

        $kernel = $app->kernel();
        if ($kernel->standIns instanceof BodyFakes) {
            $kernel->standIns->forget();
        }
        $kernel->orm->recordLazyLoads(new LazyLoads());

        return $client;
    }

    /** The current test's client: nobody, in no tenant or panel. */
    public static function client(): Client
    {
        return self::$client ?? throw new \LogicException('No application is under test: call Testing::use($app) first, in a beforeEach.');
    }

    /** The clock the current test's application was built with. */
    public static function clock(): TestClock
    {
        return self::$clock ?? throw new \LogicException('No clock is under test: build the application with a TestClock and pass it to Testing::use().');
    }

    /**
     * Forgets the application, so a later test cannot reach it by accident --
     * nor meet a query or action this one faked, or add to its record of
     * lazy loads, should it reach the same application.
     */
    public static function forget(): void
    {
        $kernel = self::$client?->app->kernel();
        if ($kernel?->standIns instanceof BodyFakes) {
            $kernel->standIns->forget();
        }
        $kernel?->orm->recordLazyLoads(null);

        self::$client = null;
        self::$clock = null;
    }

    /** Loads the Pest-style functions, for a suite's Pest.php. */
    public static function functions(): void
    {
        require_once __DIR__ . '/test-functions.php';
    }
}
