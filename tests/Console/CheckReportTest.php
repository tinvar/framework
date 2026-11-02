<?php

declare(strict_types=1);

/**
 * `tinvar check --json` (ADR 0156): each problem located at the class,
 * method or property it names, by reflection, and none for a problem that
 * names nothing the autoloader knows.
 */

namespace Tinvar\Tests\Console\CheckReport;

use Tinvar\Auth\Violation;
use Tinvar\Console\CheckReport;

final class Located
{
    public function __construct(
        public string $promoted = '',
    ) {}

    public function method(): void {}
}

it('locates a violation at the method its where names', function () {
    $problem = CheckReport::problem(new Violation('a-rule', Located::class . '::method()', 'is wrong'));

    expect($problem)->toBe([
        'message' => 'is wrong',
        'rule' => 'a-rule',
        'where' => Located::class . '::method()',
        'file' => __FILE__,
        'line' => new \ReflectionMethod(Located::class, 'method')->getStartLine(),
    ]);
});

it('locates a promoted property at its line, and a plain message at the class it names', function () {
    expect(CheckReport::locate(Located::class . '::$promoted'))->toBe([__FILE__, 19])
        ->and(CheckReport::problem('Something about ' . Located::class . ' is wrong')['line'])->toBe(new \ReflectionClass(Located::class)->getStartLine());
});

it('gives no location for a problem naming nothing it can load', function () {
    expect(CheckReport::problem('composer.lock pins nothing for vendor/package')['file'])->toBeNull()
        ->and(CheckReport::locate('No\\Such\\ClassAnywhere::method()'))->toBeNull();
});

it('writes every section, a passed one with its lines and a failed one with its problems, and whether all passed', function () {
    $report = json_decode(CheckReport::json([
        ['Fine:', [], ['ok: fine']],
        ['Broken:', ['one thing'], ['ok: never shown']],
    ]), true, 16, JSON_THROW_ON_ERROR);

    expect($report['passed'])->toBeFalse()
        ->and($report['sections'][0])->toBe(['heading' => 'Fine:', 'problems' => [], 'passed' => ['ok: fine']])
        ->and($report['sections'][1]['problems'][0]['message'])->toBe('one thing')
        ->and($report['sections'][1]['passed'])->toBe([]);
});
