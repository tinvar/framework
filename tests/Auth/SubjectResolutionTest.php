<?php

declare(strict_types=1);

/**
 * PolicyGate asked class_exists() about a check's argument before looking at
 * the resolved arguments. class_exists() is case-insensitive and sees every
 * declared class, so a model input named like one -- $directory, or anything
 * a class_alias covers -- was decided as the class-name string instead of
 * the row it held.
 */

namespace Tinvar\Tests\Auth\SubjectResolution;

use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';

// A global alias, as an application's alias map would register. Its name
// matches the parameter below once case is ignored.
if (!class_exists('TinvarSubjectShelf', false)) {
    class_alias(Rack::class, 'TinvarSubjectShelf');
}

/** Answers class-level abilities for a string; a row only for its owner. */
final class OwnerOnlyRackPolicy
{
    /** @var list<string> */
    public static array $received = [];

    public function view(?object $user, Rack|string $rack): bool
    {
        self::$received[] = get_debug_type($rack);

        return \is_string($rack);
    }
}

function gate(): PolicyGate
{
    OwnerOnlyRackPolicy::$received = [];

    return new PolicyGate((new PolicyRegistry())->register(Rack::class, OwnerOnlyRackPolicy::class), new AuthContext());
}

it('decides about the resolved model when its name is also a class', function (string $argument) {
    $rack = new Rack();

    $allowed = gate()->allows(new Authorize('view', $argument), [$argument => $rack]);

    expect($allowed)->toBeFalse()
        ->and(OwnerOnlyRackPolicy::$received)->toBe([Rack::class]);
})->with([
    'a built-in class name' => ['directory'],
    'a class alias' => ['tinvarSubjectShelf'],
]);

it('still treats a class named in the attribute as the class', function () {
    $allowed = gate()->allows(new Authorize('view', Rack::class), []);

    expect($allowed)->toBeTrue()
        ->and(OwnerOnlyRackPolicy::$received)->toBe(['string']);
});
