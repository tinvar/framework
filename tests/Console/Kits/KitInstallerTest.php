<?php

declare(strict_types=1);

/**
 * The kit seam (ADR 0155): a package declares the kit it is in its
 * composer.json, and `kit:install` builds it from the application's own
 * autoloader once Composer has installed it.
 */

namespace Tinvar\Tests\Console\Kits;

use Tinvar\Console\Kits\AppKit;
use Tinvar\Console\Kits\KitDeclaration;
use Tinvar\Console\Kits\KitInstaller;
use Tinvar\Console\Kits\KitTarget;

final class NotesKit implements AppKit
{
    public function install(KitTarget $app): array
    {
        file_put_contents("{$app->root}/kit.txt", "installed under {$app->namespace}\n");

        return ["`{$app->console} migrate`"];
    }
}

/** @param list<array<string, mixed>> $packages */
function project(array $packages): string
{
    $root = sys_get_temp_dir() . '/tinvar-kits-' . bin2hex(random_bytes(4));
    mkdir("{$root}/vendor/composer", 0o755, true);
    file_put_contents("{$root}/vendor/composer/installed.json", json_encode(['packages' => $packages]));

    return $root;
}

it('reads a kit\'s declaration from its composer.json, and refuses one half written', function () {
    expect(KitDeclaration::of(['name' => 'tinvar/accounts', 'extra' => ['tinvar' => ['kit' => ['name' => 'accounts', 'title' => 'Accounts', 'class' => 'Tinvar\\Accounts\\AccountsKit']]]]))
        ->toEqual(new KitDeclaration('tinvar/accounts', 'accounts', 'Accounts', 'Tinvar\\Accounts\\AccountsKit'))
        ->and(KitDeclaration::of(['name' => 'tinvar/mail']))->toBeNull()
        ->and(static fn () => KitDeclaration::of(['name' => 'x/y', 'extra' => ['tinvar' => ['kit' => ['name' => 'Bad Name', 'class' => 'X']]]]))->toThrow(\InvalidArgumentException::class);
});

it('installs the kit a package Composer installed declares, into the app, and says what next', function () {
    $root = project([['name' => 'acme/notes', 'extra' => ['tinvar' => ['kit' => ['name' => 'notes', 'title' => 'Notes', 'class' => NotesKit::class]]]]]);
    $out = '';

    new KitInstaller($root, new KitTarget($root, 'App', 'bin/tinvar', "{$root}/database/migrations"), static function (string $t) use (&$out): void {
        $out .= $t;
    })->install('acme/notes');

    expect(file_get_contents("{$root}/kit.txt"))->toBe("installed under App\n")
        ->and($out)->toBe("Installing Notes (acme/notes)\n  `bin/tinvar migrate`\n")
        ->and(array_keys(KitInstaller::installed($root)))->toBe(['acme/notes']);
    exec('rm -rf ' . escapeshellarg($root));
});

it('refuses a package that is not installed, and a kit class that is no AppKit', function () {
    $root = project([['name' => 'acme/odd', 'extra' => ['tinvar' => ['kit' => ['name' => 'odd', 'class' => \stdClass::class]]]]]);
    $installer = new KitInstaller($root, new KitTarget($root, 'App', 'bin/tinvar', $root), static function (string $t): void {});

    expect(static fn () => $installer->install('acme/missing'))->toThrow(\RuntimeException::class, 'composer require acme/missing')
        ->and(static fn () => $installer->install('acme/odd'))->toThrow(\RuntimeException::class, 'not a class implementing');
    exec('rm -rf ' . escapeshellarg($root));
});
