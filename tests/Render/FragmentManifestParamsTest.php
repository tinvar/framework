<?php

declare(strict_types=1);

/**
 * How a built page is keyed: by its route and the params it was built with.
 *
 * Params reach a route as strings -- the bootstrap endpoint stringifies each
 * one before it looks a page up -- while the build writes the manifest as
 * JSON, where a numeric param is naturally a number.
 */

namespace Tinvar\Tests\Render\ManifestParams;

use Tinvar\Render\FragmentManifest;

it('finds a page the build recorded with a numeric param', function () {
    $path = tempnam(sys_get_temp_dir(), 'manifest');
    file_put_contents($path, json_encode([
        'docs.page' => [['id' => 'toc', 'component' => 'Toc', 'props' => [], 'params' => ['page' => 2]]],
    ]));

    try {
        $manifest = FragmentManifest::fromFile($path);

        expect($manifest->boundariesFor('docs.page', ['page' => '2']))->toHaveCount(1)
            ->and($manifest->boundariesFor('docs.page', ['page' => '3']))->toBe([]);
    } finally {
        unlink($path);
    }
});

it('treats a numeric param and its string as the same page', function () {
    $manifest = (new FragmentManifest())->declare('docs.page', 'toc', 'Toc', [], ['page' => '2']);

    expect(fn () => $manifest->declare('docs.page', 'toc', 'Toc', [], ['page' => 2]))
        ->toThrow(\InvalidArgumentException::class, "two deferred boundaries with id 'toc'")
        ->and($manifest->boundariesFor('docs.page', ['page' => 2]))->toHaveCount(1);
});

it('refuses a param the build wrote as something other than a string or an integer', function () {
    expect(fn () => new FragmentManifest([
        'docs.page' => [['id' => 'toc', 'component' => 'Toc', 'params' => ['page' => ['nested']]]],
    ]))->toThrow(\InvalidArgumentException::class, "'page'");
});

it('lists each route with a built page once', function () {
    $manifest = (new FragmentManifest())
        ->declare('docs.page', 'a', 'Toc', [], ['page' => '1'])
        ->declare('docs.page', 'a', 'Toc', [], ['page' => '2'])
        ->declare('racks.show', 'h', 'UnitHistory', [], ['rack' => 'r-1']);

    expect($manifest->pages())->toBe(['docs.page', 'racks.show']);
});
