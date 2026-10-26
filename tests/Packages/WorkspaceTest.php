<?php

declare(strict_types=1);

/**
 * The workspace rule: every package requires its siblings at one
 * constraint, a single minor, so one tag releases them together; each is
 * named for its directory, autoloads what the layer rule reads, requires
 * exactly the layers it may use, and carries no extra.tinvar (ADR 0055).
 */

namespace Tinvar\Tests\Packages\Workspace;

use Tinvar\Packages\Layers;
use Tinvar\Packages\Workspace;

/** Two layers, each a package, as a workspace of two would map them. */
function layers(): Layers
{
    return new Layers(
        ['support' => ['Tinvar\\Support\\'], 'database' => ['Tinvar\\Orm\\']],
        ['database' => ['support']],
        ['packages/support/src/Support' => 'Tinvar\\Support\\', 'packages/database/src/Orm' => 'Tinvar\\Orm\\'],
        ['packages/support/src' => 'support', 'packages/database/src' => 'database'],
    );
}

/**
 * A workspace that passes, changed by $change.
 *
 * @param \Closure(array<string, array<mixed>>): array<string, array<mixed>> $change given and giving path => manifest
 * @return list<string>
 */
function violations(?\Closure $change = null): array
{
    $files = [
        'packages/support/composer.json' => [
            'name' => 'tinvar/support',
            'require' => ['php' => '>=8.5'],
            'require-dev' => ['pestphp/pest' => '^4.0'],
            'autoload' => ['psr-4' => ['Tinvar\\Support\\' => 'src/Support/'], 'files' => ['aliases.php']],
        ],
        'packages/database/composer.json' => [
            'name' => 'tinvar/database',
            'require' => ['php' => '>=8.5', 'tinvar/support' => '0.4.*'],
            'autoload' => ['psr-4' => ['Tinvar\\Orm\\' => 'src/Orm/']],
        ],
        'composer.json' => [
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/support', 'options' => ['symlink' => true, 'versions' => ['tinvar/support' => '0.4.x-dev']]],
                ['type' => 'path', 'url' => 'packages/database', 'options' => ['symlink' => true, 'versions' => ['tinvar/database' => '0.4.x-dev']]],
            ],
            'require' => ['php' => '>=8.5', 'tinvar/database' => '0.4.*@dev'],
            'require-dev' => ['tinvar/support' => '0.4.*@dev'],
        ],
    ];
    $files = $change === null ? $files : $change($files);

    $root = sys_get_temp_dir() . '/tinvar-workspace-' . bin2hex(random_bytes(4));
    foreach ($files as $path => $manifest) {
        if (!is_dir(\dirname("{$root}/{$path}"))) {
            mkdir(\dirname("{$root}/{$path}"), 0o777, true);
        }
        file_put_contents("{$root}/{$path}", \is_string($manifest) ? $manifest : json_encode($manifest));
    }

    try {
        return (new Workspace($root, layers()))->check();
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
}

it('passes the repository as it is', function () {
    expect((new Workspace(\dirname(__DIR__, 4), Layers::framework()))->check())->toBe([]);
});

it('passes a workspace in step', function () {
    expect(violations())->toBe([]);
});

it('fails a sibling required at another constraint, in require or require-dev', function () {
    $violations = violations(static function (array $files): array {
        $files['packages/database/composer.json']['require']['tinvar/support'] = '0.5.*';
        $files['packages/support/composer.json']['require-dev']['tinvar/database'] = '0.4.*';
        $files['packages/database/composer.json']['require-dev']['tinvar/support'] = '0.4.*';

        return $files;
    });

    expect($violations)->toBe([
        'packages/database/composer.json require tinvar/support is 0.5.*, where the packages require each other at 0.4.*: one constraint, so one tag releases them together.',
    ]);
});

it('fails siblings required at anything but one minor version', function (string $constraint) {
    $violations = violations(static function (array $files) use ($constraint): array {
        $files['packages/database/composer.json']['require']['tinvar/support'] = $constraint;

        return $files;
    });

    expect($violations)->toContain("packages/: siblings are required at {$constraint}, which is not one minor version (0.4.*).");
})->with(['a caret' => ['^0.4'], 'anything' => ['*'], 'a dev branch' => ['dev-main'], 'a whole major' => ['1.*'], 'an exact release' => ['0.4.1']]);

it('fails a package named for another directory, and one carrying extra.tinvar', function () {
    $violations = violations(static function (array $files): array {
        $files['packages/support/composer.json']['name'] = 'tinvar/helpers';
        $files['packages/database/composer.json']['extra'] = ['tinvar' => ['codemods' => []]];

        return $files;
    });

    expect($violations)->toBe([
        'packages/database/composer.json: carries extra.tinvar, which marks an application\'s package for the allowlist; the framework\'s packages carry none.',
        'packages/support/composer.json: is named "tinvar/helpers"; a package in packages/support is tinvar/support.',
    ]);
});

it('fails a require its layer may not use, and a layer it uses that it does not require', function () {
    $above = violations(static function (array $files): array {
        $files['packages/support/composer.json']['require']['tinvar/database'] = '0.4.*';

        return $files;
    });
    $missing = violations(static function (array $files): array {
        unset($files['packages/database/composer.json']['require']['tinvar/support']);
        $files['packages/database/composer.json']['require-dev']['tinvar/support'] = '0.4.*';

        return $files;
    });

    expect($above)->toBe(['packages/support/composer.json: requires tinvar/database, which the support layer may not use.'])
        ->and($missing)->toBe(['packages/database/composer.json: does not require tinvar/support, which the database layer uses; without it the package installs without what it names.']);
});

it('fails a sibling that is no package', function () {
    $violations = violations(static function (array $files): array {
        $files['packages/database/composer.json']['require-dev']['tinvar/nowhere'] = '0.4.*';

        return $files;
    });

    expect($violations)->toBe(['packages/database/composer.json: require-dev names tinvar/nowhere, which is no package in packages/.']);
});

it('fails a PSR-4 map other than the one the layer rule reads', function () {
    $violations = violations(static function (array $files): array {
        $files['packages/database/composer.json']['autoload']['psr-4'] = ['Tinvar\\Orm\\' => 'src/Orm/', 'Tinvar\\Http\\' => 'src/Http/'];

        return $files;
    });

    expect($violations)->toBe([
        'packages/database/composer.json: autoloads Tinvar\Http\ from packages/database/src/Http, Tinvar\Orm\ from packages/database/src/Orm, and the layer rule reads Tinvar\Orm\ from packages/database/src/Orm; they have to be the same directories and prefixes.',
    ]);
});

it('fails a package the layer rule does not read as its own layer', function () {
    $violations = violations(static function (array $files): array {
        $files['packages/extra/composer.json'] = ['name' => 'tinvar/extra', 'require' => ['tinvar/support' => '0.4.*']];

        return $files;
    });

    expect($violations)->toContain('packages/extra/composer.json: packages/extra/src is no layer; the layer rule has to read it as extra.');
});

it('fails the workspace requiring a package at anything but the lockstep, or serving it as another', function () {
    $violations = violations(static function (array $files): array {
        $files['composer.json']['require']['tinvar/database'] = '@dev';
        $files['composer.json']['repositories'][0]['options']['versions']['tinvar/support'] = 'dev-main';

        return $files;
    });

    expect($violations)->toBe([
        'composer.json: requires tinvar/database at "@dev", where the workspace requires each package at 0.4.*@dev.',
        'composer.json: the path repository serves tinvar/support as "dev-main", where the packages require 0.4.*; it serves 0.4.x-dev.',
    ]);
});

it('fails closed on a manifest it cannot read', function () {
    $violations = violations(static function (array $files): array {
        $files['packages/support/composer.json'] = '{"name": ';

        return $files;
    });

    expect($violations)->toContain('packages/support/composer.json: cannot be read as a JSON object.');
});
