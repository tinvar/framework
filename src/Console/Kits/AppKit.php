<?php

declare(strict_types=1);

namespace Tinvar\Console\Kits;

/**
 * A kit: a Composer package that adds a whole feature to an application --
 * accounts, say -- by writing the application's own classes, migrations
 * and views once, then leaving them to the application (ADR 0155).
 *
 * A package says it is one in its composer.json, and nowhere else:
 *
 *     "extra": {"tinvar": {"kit": {
 *         "name": "accounts",
 *         "title": "Accounts: sign-up, sign-in, password reset and email links",
 *         "class": "Tinvar\\Accounts\\AccountsKit"
 *     }}}
 *
 * `tinvar new` offers every kit its source has by that name -- `--accounts`
 * for the one named `accounts`, `--kit=<package>` for any -- requires the
 * package, and once Composer has installed it runs `bin/tinvar kit:install
 * <package>` in the new application, which builds the class and calls
 * install(). The same command adds a kit to an application that already
 * exists.
 *
 * install() writes into the application it is handed and nowhere else,
 * refuses to write over a file that is there, and says what to do next.
 * It runs in the application's own process, as its own code: nothing it
 * fetched runs.
 */
interface AppKit
{
    /**
     * Writes the kit into the application.
     *
     * @return list<string> the next steps, each a command or a sentence
     * @throws \RuntimeException where it cannot, saying why and what to do
     */
    public function install(KitTarget $app): array;
}
