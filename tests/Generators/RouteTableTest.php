<?php

declare(strict_types=1);

/**
 * make:page's edit to the renderer's route table: one entry and its import,
 * once, and nothing where the table is not written as make:page writes one.
 */

namespace Tinvar\Tests\Generators\RouteTable;

use Tinvar\Generators\RegistrationRefused;
use Tinvar\Generators\RouteTable;
use Tinvar\Support\PrivateDirectory;

const TABLE = <<<'TSX'
import type { ReactNode } from 'react';
import HomePage from '../pages/app/home';

type Page = (props: { data: never; params: never }) => ReactNode;

const ROUTES: Record<string, { Page: Page; layouts: readonly 'app'[] }> = {
  'app/home': { Page: HomePage as Page, layouts: ['app'] },
  // A system page.
  'app/error': { Page: HomePage as Page, layouts: [] },
};

TSX;

function table(string $source, \Closure $body): string
{
    $directory = PrivateDirectory::create('route-table');
    $file = "{$directory}/rsc.tsx";
    file_put_contents($file, $source);
    try {
        $body($file);

        return (string) file_get_contents($file);
    } finally {
        @unlink($file);
        PrivateDirectory::remove($directory);
    }
}

it('adds the view after the last entry, and imports its component after the last import', function () {
    $after = table(TABLE, function (string $file): void {
        expect(RouteTable::add($file, 'notes/show', '../pages/notes/show', ['app']))->toBeTrue()
            ->and(RouteTable::add($file, 'notes/show', '../pages/notes/show', ['app']))->toBeFalse()
            ->and(RouteTable::add($file, 'app/home', '../pages/app/home', ['app']))->toBeFalse();
    });

    expect($after)->toContain("import HomePage from '../pages/app/home';\nimport NotesShowPage from '../pages/notes/show';\n")
        ->and($after)->toContain("  'app/error': { Page: HomePage as Page, layouts: [] },\n  'notes/show': { Page: NotesShowPage as Page, layouts: ['app'] },\n};");
});

it('picks a component name the file does not use', function () {
    $after = table(TABLE, function (string $file): void {
        RouteTable::add($file, 'app/home-page', '../pages/app/home-page', []);
        RouteTable::add($file, 'home', '../pages/home', []);
    });

    expect($after)->toContain("import HomePage2 from '../pages/home';");
});

it('refuses a table it cannot read, or a layout its type does not list, and leaves it as it was', function (string $source, array $layouts, string $why) {
    $after = table($source, function (string $file) use ($layouts, $why): void {
        expect(fn () => RouteTable::add($file, 'notes', '../pages/notes', $layouts))->toThrow(RegistrationRefused::class, $why);
    });

    expect($after)->toBe($source);
})->with([
    'an unlisted layout' => [TABLE, ['admin'], "does not list the layout 'admin'"],
    'an entry over two lines' => [str_replace("{ Page: HomePage as Page, layouts: ['app'] },", "{\n    Page: HomePage as Page,\n    layouts: ['app'],\n  },", TABLE), [], 'not one entry'],
    'a spread' => [str_replace("  // A system page.\n", "  ...OTHER,\n", TABLE), [], 'not one entry'],
    'no table' => ["import x from 'y';\nexport const PAGES = {\n};\n", [], 'declares no const ROUTES'],
    'a table built' => ["import x from 'y';\nconst ROUTES = build({\n});\n", [], 'not an object literal'],
]);

it('refuses a view or a path that could end its string', function (string $view, string $component) {
    $after = table(TABLE, function (string $file) use ($view, $component): void {
        expect(fn () => RouteTable::add($file, $view, $component, []))->toThrow(RegistrationRefused::class);
    });

    expect($after)->toBe(TABLE);
})->with([
    'a quote in the view' => ["notes'; evil()", '../pages/notes'],
    'a quote in the path' => ['notes', "../pa'ges/notes"],
    'a bare path' => ['notes', 'pages/notes'],
]);

it('names an import path from the entry\'s directory', function () {
    expect(RouteTable::importPath('/app/resources/entries/rsc.tsx', '/app/resources/pages/notes/show'))->toBe('../pages/notes/show')
        ->and(RouteTable::importPath('/app/resources/rsc.tsx', '/app/resources/pages/notes'))->toBe('./pages/notes');
});
